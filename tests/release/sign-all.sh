#!/usr/bin/env bash
# Tests cli/scripts/sign-all.sh and cli/scripts/sign-release.sh with fake gh,
# sops, ssh-add and ssh-keygen commands. No test reads a terminal.
# Run: bash tests/release/sign-all.sh   (needs bash, git and jq)
set -uo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0
cli=cli/v2.8.0
sk1=skills/loupe/v0.59.7
sk2=skills/loupe-lifecycle/v0.4.23

mkdir -p "$tmp/bin" "$tmp/home/.config/loupe"
echo "encrypted" > "$tmp/home/.config/loupe/release-signing.enc.yaml"

cat > "$tmp/bin/gh" <<'FAKE'
#!/usr/bin/env bash
state="$FAKE_STATE"
args="$*"
tag="${3:-}"
has() { grep -qx -- "$1" "$state/$2" 2>/dev/null; }
case "$1 $2" in
    "release view")
        if has "$tag" drafts; then is_draft=true
        elif has "$tag" published; then is_draft=false
        else exit 1; fi
        case "$args" in
            *isDraft,assets*) printf '%s\tfalse\n' "$is_draft" ;;
            *"--json isDraft"*) echo "$is_draft" ;;
            *assets*) if [ "$is_draft" = false ] && ! has "$tag" unsigned; then echo checksums.txt.sig; fi ;;
        esac ;;
    "release download")
        dir=""; prev=""
        for a in "$@"; do [ "$prev" = --dir ] && dir="$a"; prev="$a"; done
        echo "$tag" > "$dir/checksums.txt"
        if has "$tag" published && ! has "$tag" unsigned; then
            if has "$tag" badsig; then echo BAD > "$dir/checksums.txt.sig"; else echo GOOD > "$dir/checksums.txt.sig"; fi
        fi ;;
    "attestation verify")
        file="$3"; wf=""; ref=""; prev=""
        for a in "$@"; do
            [ "$prev" = --signer-workflow ] && wf="$(basename "$a")"
            [ "$prev" = --source-ref ] && ref="$a"
            prev="$a"
        done
        if has "$(cat "$file")" attest_fail; then echo "attestation failed" >&2; exit 1; fi
        sed -e "s#cli-release.yml@refs/tags/cli/v1.0.0#$wf@$ref#g" -e "s#refs/tags/cli/v1.0.0#$ref#g" \
            -e "s#@COMMIT@#$FAKE_COMMIT#g" "$FAKE_TESTDATA" ;;
    "api repos/loupe-ac/loupe/releases") cat "$state/drafts" 2>/dev/null ;;
    "release upload") echo "upload $tag" >> "$state/log" ;;
    "release edit") echo "publish $tag" >> "$state/log" ;;
    "workflow run") echo "workflow $args" >> "$state/log" ;;
    *) echo "fake gh: unexpected: $args" >&2; exit 2 ;;
esac
FAKE
cat > "$tmp/bin/ssh-keygen" <<'FAKE'
#!/usr/bin/env bash
mode="$2"
if [ "$mode" = sign ]; then
    for a in "$@"; do file="$a"; done
    echo GOOD > "$file.sig"
    exit 0
fi
sig=""; prev=""
for a in "$@"; do [ "$prev" = -s ] && sig="$a"; prev="$a"; done
cat > /dev/null
[ "$(cat "$sig")" = GOOD ]
FAKE
printf '#!/usr/bin/env bash\necho fake-private-key\n' > "$tmp/bin/sops"
printf '#!/usr/bin/env bash\ncat > /dev/null\nexit 0\n' > "$tmp/bin/ssh-add"
chmod +x "$tmp/bin/"*

# A repository with an origin, the scripts and the plugin versions.
work="$tmp/repo"
git init -q -b main --bare "$tmp/origin.git"
git init -q -b main "$work"
git -C "$work" remote add origin "$tmp/origin.git"
mkdir -p "$work/cli/scripts" "$work/cli/internal/update" "$work/plugins/loupe/.claude-plugin" "$work/plugins/loupe-lifecycle/.claude-plugin"
cp "$repo_root/cli/scripts/sign-all.sh" "$repo_root/cli/scripts/sign-release.sh" "$repo_root/cli/scripts/sign-release-check.sh" "$work/cli/scripts/"
cp "$repo_root/cli/internal/update/release_keys" "$work/cli/internal/update/"
echo '{"version":"0.59.7"}' > "$work/plugins/loupe/.claude-plugin/plugin.json"
echo '{"version":"0.4.23"}' > "$work/plugins/loupe-lifecycle/.claude-plugin/plugin.json"
git -C "$work" add -A
git -C "$work" -c user.email=t@t -c user.name=t -c commit.gpgsign=false commit -q -m base
git -C "$work" push -q origin main
export FAKE_COMMIT="$(git -C "$work" rev-parse HEAD)"
export FAKE_TESTDATA="$repo_root/cli/scripts/testdata/attestation.json"
export HOME="$tmp/home" SSH_AUTH_SOCK=/nonexistent PATH="$tmp/bin:$PATH"

# reset <drafts...>: a fresh fake state with these drafts.
reset() {
    export FAKE_STATE="$tmp/state"
    rm -rf "$FAKE_STATE"
    mkdir -p "$FAKE_STATE"
    : > "$FAKE_STATE/drafts"
    for t in "$@"; do echo "$t" >> "$FAKE_STATE/drafts"; done
    : > "$FAKE_STATE/log"
}
state() { echo "$1" >> "$FAKE_STATE/$2"; }
run() {
    (cd "$work" && bash cli/scripts/sign-all.sh "$@" < /dev/null > "$tmp/out" 2> "$tmp/err")
    code=$?
}
check() {
    if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: got '$2', want '$3'"; failures=$((failures + 1)); fi
}
uploads() { grep '^upload' "$FAKE_STATE/log" | tr '\n' ' '; }

reset $sk1 $sk2 $cli
run $cli --yes
check "all drafts sign with --yes and no terminal" "$code" 0
check "skills upload before the CLI" "$(uploads)" "upload $sk1 upload $sk2 upload $cli "
check "the CLI release starts Homebrew" "$(grep -c '^workflow' "$FAKE_STATE/log")" 1

reset $sk1 $sk2 $cli
run $cli
check "no --yes and no terminal signs nothing" "$(uploads)" ""

reset $sk1 $sk2 $cli
state $cli attest_fail
run $cli --yes
check "a failed CLI attestation refuses" "$code" 1
check "a failed CLI attestation signs no skills draft" "$(uploads)" ""

reset $sk1 $sk2 $cli
state $sk2 attest_fail
run $cli --yes
check "a failed skills attestation refuses" "$code" 1
check "a failed skills attestation signs no other draft" "$(uploads)" ""

reset $sk1 $cli
run $cli --yes
check "a missing expected skills draft refuses" "$code" 1
check "a missing expected skills draft signs nothing" "$(uploads)" ""

reset $sk1 $cli
state $sk2 published; state $sk2 unsigned
run $cli --yes
check "a published skills release with no signature refuses" "$code" 1
check "a published skills release with no signature signs nothing" "$(uploads)" ""

reset $sk1 $cli
state $sk2 published; state $sk2 badsig
run $cli --yes
check "a published skills release with a bad signature refuses" "$code" 1
check "a published skills release with a bad signature signs nothing" "$(uploads)" ""

reset $sk1 $cli
state $sk2 published
run $cli --yes
check "a published and signed skills release passes" "$code" 0
check "only the drafts are signed" "$(uploads)" "upload $sk1 upload $cli "

reset $sk1 $sk2 $cli skills/loupe/v0.59.6
run $cli --yes
check "a second draft of one package refuses" "$code" 1
check "a second draft signs nothing" "$(uploads)" ""

reset $cli
state $sk1 published; state $sk2 published
run $cli --yes
check "a release that changes only the CLI signs the CLI" "$(uploads)" "upload $cli "

reset $sk1
(cd "$work" && bash cli/scripts/sign-release.sh $sk1 --check-only < /dev/null > /dev/null 2>&1)
check "--check-only signs nothing" "$(uploads)" ""

reset $sk1
(cd "$work" && SSH_AUTH_SOCK=/x bash cli/scripts/sign-release.sh $sk1 --yes < /dev/null > /dev/null 2>&1)
check "--yes signs without a terminal" "$(uploads)" "upload $sk1 "

reset $sk1
state $sk1 attest_fail
(cd "$work" && SSH_AUTH_SOCK=/x bash cli/scripts/sign-release.sh $sk1 --yes < /dev/null > /dev/null 2>&1)
check "--yes still refuses a failed attestation" "$(uploads)" ""

[ "$failures" -eq 0 ] || { echo "$failures failed"; exit 1; }
echo "all passed"
