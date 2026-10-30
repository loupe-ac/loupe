#!/usr/bin/env bash
# Shared by bin/tf-secrets and bin/rotate-secrets. It reads and writes the
# secret store, an encrypted sops file, and reads Terraform inputs from a
# tfvars file. No function here prints a secret value.
#
# Callers set TF_SECRETS_ROOT (the Terraform folder) and TF_SECRETS_TFVARS (the
# tfvars file), and may set TF_SECRETS_STORE (the store file) and
# TF_SECRETS_FAIL (a function that reports an error and exits).

ts_fail() {
    if declare -F "${TF_SECRETS_FAIL:-}" > /dev/null; then "$TF_SECRETS_FAIL" "$@"; fi
    echo "tf-secrets: $*" >&2
    exit 1
}

ts_absolute() { printf '%s/%s' "$(cd "$(dirname "$1")" && pwd)" "$(basename "$1")"; }

# The store file: its own checkout first, then the main checkout, because a
# worktree has no gitignored file of its own. Prints nothing when none exists.
# sops exec-env takes the format from the extension alone, so the name ends .yaml.
store_file() {
    local own main
    if [ -n "${TF_SECRETS_STORE:-}" ]; then
        [[ $TF_SECRETS_STORE == *.yaml ]] || ts_fail "the store file name must end in .yaml."
        [ -f "$TF_SECRETS_STORE" ] && printf '%s' "$TF_SECRETS_STORE"
        return 0
    fi
    own="$TF_SECRETS_ROOT/secrets.enc.yaml"
    if [ -f "$own" ]; then printf '%s' "$own"; return 0; fi
    main=$(cd "$(git -C "$TF_SECRETS_ROOT" rev-parse --path-format=absolute --git-common-dir 2> /dev/null)/.." 2> /dev/null && pwd) || return 0
    [ ! -f "$main/terraform/secrets.enc.yaml" ] || printf '%s' "$main/terraform/secrets.enc.yaml"
}

# The age recipients of the live store, comma separated, so a second store
# encrypts to the same keys. Empty when there is no live store.
live_recipients() {
    local live
    live=$(TF_SECRETS_STORE=${TF_SECRETS_LIVE_STORE:-} store_file) || return 0
    [ -n "$live" ] || return 0
    awk '$1 == "recipient:" { printf "%s%s", sep, $2; sep = "," }' "$live"
}

# Where a new store file goes.
store_target() {
    if [ -n "${TF_SECRETS_STORE:-}" ]; then printf '%s' "$TF_SECRETS_STORE"; else printf '%s' "$TF_SECRETS_ROOT/secrets.enc.yaml"; fi
}

list_file() { printf '%s' "${TF_SECRETS_LIST:-$TF_SECRETS_ROOT/secrets.list}"; }

# One "<terraform name> <environment name>" pair per line.
list_pairs() {
    awk 'NF && $1 !~ /^#/ { print $1, ($2 != "" ? $2 : "TF_VAR_" $1) }' "$(list_file)"
}

# The environment name of a listed Terraform input. Fails when it is not listed.
env_name_of() {
    local found
    found=$(list_pairs | awk -v n="$1" '$1 == n { print $2; exit }')
    [ -n "$found" ] || return 1
    printf '%s' "$found"
}

# The base64 of a stored value, empty when the name is not in the store.
store_b64() {
    local file=${2:-}
    [ -n "$file" ] || file=$(store_file)
    [ -n "$file" ] || ts_fail "no secret store is set up."
    sops decrypt --input-type yaml --output-type json "$file" | jq -j --arg n "$1" '.[$n] // empty' | openssl base64 -A
}

# The names the store holds. The keys of a sops file are not encrypted.
store_names() {
    local file
    file=$(store_file)
    [ -n "$file" ] || return 0
    awk -F: '/^[A-Za-z_][A-Za-z0-9_]*:/ && $1 != "sops" { print $1 }' "$file"
}

# Writes name and value pairs in one rename. Each value reaches sops on
# standard input. A pair is read back before the rename.
store_set_many() {
    local file tmp tmpdir i n names=() values=() bad=() dir created=0
    while [ $# -gt 1 ]; do names+=("$1"); values+=("$2"); shift 2; done
    n=${#names[@]}
    [ "$n" -gt 0 ] && [ $# -eq 0 ] || ts_fail "store_set_many takes name and value pairs."
    file=$(store_file)
    if [ -z "$file" ]; then file=$(store_target); created=1; fi
    dir=$(dirname "$file")
    tmpdir=$(mktemp -d "$dir/.rotate-secrets.tmp.XXXXXX")
    tmp=$tmpdir/secrets.enc.yaml
    if [ "$created" = 1 ]; then
        printf '{}' | SOPS_AGE_RECIPIENTS=${SOPS_AGE_RECIPIENTS:-$(live_recipients)} sops encrypt --input-type json --output-type yaml --filename-override "$file" /dev/stdin > "$tmp" \
            || { rm -rf -- "$tmpdir"; ts_fail "could not start the secret store. Set SOPS_AGE_RECIPIENTS or add a .sops.yaml."; }
    else
        cp -p "$file" "$tmp" || { rm -rf -- "$tmpdir"; ts_fail "could not copy the store."; }
    fi
    for ((i = 0; i < n; i++)); do
        printf '%s' "${values[i]}" | jq -Rs . | sops set --input-type yaml --output-type yaml --value-stdin "$tmp" "[\"${names[i]}\"]" \
            || { rm -rf -- "$tmpdir"; ts_fail "could not write ${names[i]}. The store is unchanged."; }
    done
    for ((i = 0; i < n; i++)); do
        [ "$(store_b64 "${names[i]}" "$tmp")" = "$(printf '%s' "${values[i]}" | openssl base64 -A)" ] || bad+=("${names[i]}")
    done
    if [ ${#bad[@]} -gt 0 ]; then
        rm -rf -- "$tmpdir"
        ts_fail "${bad[*]} did not read back the same. The store is unchanged."
    fi
    mv -f "$tmp" "$file"
    rmdir -- "$tmpdir"
}

# Placeholders for the required secrets, so that a read of a non-secret input
# works when terraform.tfvars no longer holds them.
placeholder_env() {
    awk '
        /^variable "/ { n = $2; gsub(/"/, "", n); d = 0; s = 0 }
        /default[ \t]*=/ { d = 1 }
        /sensitive[ \t]*=[ \t]*true/ { s = 1 }
        /^}/ { if (s && !d) print "TF_VAR_" n "=x" }
    ' "$TF_SECRETS_ROOT/variables.tf"
}

# The base64 of an input read from the tfvars file, "unset" when the input is
# null. Terraform parses the file, so a heredoc, an escape or a comment reads
# right. Terraform prints warnings on standard output, so only the last line
# counts. Standard error is dropped because a parse error quotes a line.
tfvars_file_b64() {
    local name=$1 extra=${2:-} out ph=() args line
    args=(-no-color "-var-file=$(ts_absolute "$TF_SECRETS_TFVARS")")
    [ -z "$extra" ] || args+=("-var-file=$(ts_absolute "$extra")")
    if ! env_name_of "$name" > /dev/null 2>&1; then
        while IFS= read -r line; do ph+=("$line"); done < <(placeholder_env)
    fi
    out=$(env ${ph[@]+"${ph[@]}"} TF_INPUT=0 terraform -chdir="$TF_SECRETS_ROOT" console "${args[@]}" 2> /dev/null \
        <<< "nonsensitive(var.$name == null) ? \"unset\" : base64encode(nonsensitive(var.$name))" | tail -n 1) \
        || ts_fail "terraform could not read $name."
    [[ $out =~ ^\"([A-Za-z0-9+/=]*)\"$ ]] || ts_fail "terraform could not read $name."
    printf '%s' "${BASH_REMATCH[1]}"
}
