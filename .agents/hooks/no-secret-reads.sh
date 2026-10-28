#!/usr/bin/env bash
# Refuses a tool call that reads a production secret file: terraform.tfvars, a
# Terraform state file, docker/compose/prod.env or a rotate-secrets temporary
# file. It also refuses a command that prints the state without naming it, and
# a recursive search over a folder that holds one of these files.
# Env, for tests: NO_SECRET_READS_PLACES replaces the list of secret file places.
set -uo pipefail

input="$(cat)"
tool="$(jq -r '.tool_name // "Bash"' <<<"$input")"
cwd="$(jq -r '.cwd // empty' <<<"$input")"
[ -n "$cwd" ] && [ -d "$cwd" ] && cd "$cwd"
. "$(dirname "$0")/lib/segments.sh"

deny() {
    jq -nc --arg reason "$1" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: "deny",
            permissionDecisionReason: $reason,
        },
    }'
    exit 0
}

safe_paths="To see which variables are set, run just tfvars-names. To read the live version, run just healthz. To rotate a secret, run just rotate-secrets."
read_msg="This reads a production secret file, and an agent must not read one. $safe_paths"
state_msg="This prints the Terraform state, which holds secret values. $safe_paths"
search_msg="This searches a folder that holds a production secret file, and a match on a secret line prints the value. Search with rg or git grep, which skip gitignored files. $safe_paths"

places=${NO_SECRET_READS_PLACES:-"terraform/terraform.tfvars terraform/terraform.tfvars.json terraform/terraform.tfstate terraform/terraform.tfstate.backup terraform/terraform.tfstate.bak docker/compose/prod.env"}

shopt -s nocasematch
name_re='(^|[^A-Za-z0-9_.-])(terraform\.tfvars(\.json)?|terraform\.tfstate(\.[A-Za-z0-9_-]+)*|prod\.env)($|[^A-Za-z0-9_.-])'
temp_re='(^|[^A-Za-z0-9_.-])\.rotate-secrets\.tmp\.'

protected() {
    [[ $1 =~ $name_re ]] || [[ $1 =~ $temp_re ]]
}

has_glob() {
    case "$1" in *[\*\?\[]*) return 0 ;; esac

    return 1
}

# Expands a word in the cwd the way the shell would, one match per line.
expand() {
    local IFS='' f
    shopt -s nullglob dotglob
    for f in $1; do printf '%s\n' "$f"; done
    shopt -u nullglob dotglob
}

glob_hits_secret() {
    local f
    has_glob "$1" || return 1
    while IFS= read -r f; do
        protected "$f" && return 0
    done < <(expand "$1")

    return 1
}

real_dir() {
    (cd "$1" 2>/dev/null && pwd -P)
}

real_path() {
    local d
    if [ -d "$1" ]; then real_dir "$1"; return; fi
    d="$(real_dir "$(dirname "$1")")" || return 1
    printf '%s/%s\n' "$d" "${1##*/}"
}

secrets=()
secrets_ready=0

load_secrets() {
    [ "$secrets_ready" = 1 ] && return
    secrets_ready=1
    local line root place f
    local -a roots=()
    while IFS= read -r line; do
        case "$line" in "worktree "*) roots+=("${line#worktree }") ;; esac
    done < <(git worktree list --porcelain 2>/dev/null)
    [ "${#roots[@]}" -gt 0 ] || roots=("$PWD")
    for root in "${roots[@]}"; do
        for place in $places; do
            [ -e "$root/$place" ] && secrets+=("$(real_path "$root/$place")")
        done
        shopt -s nullglob
        for f in "$root"/terraform/.rotate-secrets.tmp.*; do secrets+=("$(real_path "$f")"); done
        shopt -u nullglob
    done
}

holds_secret() {
    local dir="$1" secret
    load_secrets
    for secret in ${secrets[@]+"${secrets[@]}"}; do
        [ "$dir" = / ] && return 0
        [[ $secret == "$dir"/* ]] && return 0
    done

    return 1
}

# Words after the command that name an existing folder. A glob expands first.
# Sets found_path when a word names any existing path.
folders_of() {
    local w f
    folders=()
    found_path=0
    for w in "$@"; do
        case "$w" in -*) continue ;; '~'|'~/'*) w="$HOME${w#\~}" ;; esac
        if has_glob "$w"; then
            while IFS= read -r f; do
                [ -e "$f" ] && found_path=1
                [ -d "$f" ] && folders+=("$(real_dir "$f")")
            done < <(expand "$w")
        elif [ -e "$w" ]; then
            found_path=1
            [ -d "$w" ] && folders+=("$(real_dir "$w")")
        fi
    done
}

# Refuses when a folder that the command reads holds a secret file. A search
# command with no path reads the cwd.
check_folders() {
    local implicit="$1" dir
    shift
    folders_of "$@"
    if [ "$implicit" = 1 ] && [ "$found_path" = 0 ]; then
        folders=("$(real_dir .)")
    fi
    for dir in ${folders[@]+"${folders[@]}"}; do
        holds_secret "$dir" && deny "$search_msg"
    done
}

has_arg() {
    local pattern="$1" w
    shift
    for w in "$@"; do
        [ "$w" = -- ] && return 1
        [[ $w =~ $pattern ]] && return 0
    done

    return 1
}

judge_folder_reader() {
    local name="${1##*/}"
    shift
    case "$name" in
        grep|egrep|fgrep)
            has_arg '^(--recursive|--dereference-recursive|-[A-Za-z0-9]*[rR][A-Za-z0-9]*)$' "$@" && check_folders 1 "$@" ;;
        rgrep) check_folders 1 "$@" ;;
        rg)
            has_arg '^(--no-ignore|--no-ignore-vcs|-[A-Za-z]*u[A-Za-z]*)$' "$@" && check_folders 1 "$@" ;;
        tar)
            if has_arg '^(--create|-[A-Za-z]*c[A-Za-z]*)$' "$@" || [[ ${1:-} =~ ^[A-Za-z]*c[A-Za-z]*$ ]]; then
                check_folders 0 "$@"
            fi ;;
        zip)
            has_arg '^(--recurse-paths|-[A-Za-z]*[rR][A-Za-z]*)$' "$@" && check_folders 0 "$@" ;;
        cp)
            has_arg '^(--recursive|--archive|-[A-Za-z]*[rRa][A-Za-z]*)$' "$@" && check_folders 0 "$@" ;;
        rsync)
            has_arg '^(--recursive|--archive|--dirs|-[A-Za-z]*[radR][A-Za-z]*)$' "$@" && check_folders 0 "$@" ;;
    esac

    return 0
}

judge_terraform() {
    local sub="" sub2="" json=0 operand=0 w
    for w in "$@"; do
        case "$w" in
            -*) [ "$w" = -json ] && json=1 ;;
            *)
                if [ -z "$sub" ]; then sub="$w"
                elif [ "$sub" = state ] && [ -z "$sub2" ]; then sub2="$w"
                else operand=1
                fi ;;
        esac
    done
    [ "$sub" = state ] && { [ "$sub2" = pull ] || [ "$sub2" = show ]; } && deny "$state_msg"
    [ "$sub" = show ] && [ "$json" = 1 ] && [ "$operand" = 0 ] && deny "$state_msg"

    return 0
}

is_safe_script() {
    case "$1" in
        bin/rotate-secrets|bin/tfvars-names|bin/tfvars-missing.sh|bin/healthz-probe) return 0 ;;
        */bin/rotate-secrets|*/bin/tfvars-names|*/bin/tfvars-missing.sh|*/bin/healthz-probe) return 0 ;;
    esac

    return 1
}

# Listing and testing commands print no content, and a safe script reads the
# files in a way that prints no value.
is_allowed() {
    case "$1" in
        ls|stat|test|'['|'[['|dir|gci|Get-ChildItem|Test-Path) return 0 ;;
    esac
    case "${1##*/}" in ls|stat|test) return 0 ;; esac
    is_safe_script "$1"
}

has_substitution() {
    local w
    for w in "$@"; do
        case "$w" in *'$('*|*'`'*|*'<('*|*'>('*) return 0 ;; esac
    done

    return 1
}

# Under an allowed command, a protected word is fine unless a redirect uses it.
redirects_secret() {
    local prev="" w
    for w in "$@"; do
        if protected "$w" || glob_hits_secret "$w"; then
            case "$prev" in *[\<\>]) return 0 ;; esac
            case "$w" in *[\<\>]*) return 0 ;; esac
        fi
        prev="$w"
    done

    return 1
}

any_protected() {
    local w
    for w in "$@"; do
        protected "$w" && return 0
        glob_hits_secret "$w" && return 0
    done

    return 1
}

judge_command() {
    local -a words=("$@")
    local i=0
    skip_prefix
    local cmd="${words[i]:-}"

    if wrapped_command "${words[@]:i}"; then
        [ "$i" -gt 0 ] && any_protected "${words[@]:0:i}" && deny "$read_msg"
        judge_text "$wrapped"
        return
    fi
    [ -n "$cmd" ] || { any_protected "${words[@]}" && deny "$read_msg"; return; }

    case "${cmd##*/}" in
        terraform|tofu) judge_terraform "${words[@]:i+1}" ;;
        *) judge_folder_reader "$cmd" "${words[@]:i+1}" ;;
    esac

    local script="$cmd"
    case "${cmd##*/}" in bash|sh|zsh) script="${words[i+1]:-}" ;; esac
    if is_allowed "$cmd" || { [ "$script" != "$cmd" ] && is_safe_script "$script"; }; then
        has_substitution "${words[@]}" && any_protected "${words[@]}" && deny "$read_msg"
        redirects_secret "${words[@]}" && deny "$read_msg"
        return
    fi
    any_protected "${words[@]}" && deny "$read_msg"
}

case "$tool" in
    Read|Edit|Write)
        path="$(jq -r '.tool_input.file_path // empty' <<<"$input")"
        [ -n "$path" ] && protected "$path" && deny "$read_msg" ;;
    Grep)
        path="$(jq -r '.tool_input.path // empty' <<<"$input")"
        [ -n "$path" ] && protected "$path" && deny "$read_msg" ;;
    *)
        command="$(jq -r '.tool_input.command // empty' <<<"$input")"
        [ -n "$command" ] || exit 0
        [ "$tool" = PowerShell ] && command="${command//\\//}"
        judge_text "$command" ;;
esac

exit 0
