#!/usr/bin/env bash
# Signs a draft release, publishes it, and starts the Homebrew update for a CLI tag.
# Usage: sign-release.sh <tag> [--yes] [--check-only]
#   --yes         skips the terminal question and nothing else.
#   --check-only  runs every check, prints the summary and exits before the key is read.
# It signs only a file that `gh attestation verify` ties to the release workflow and a
# commit of main. The first key line below is the active key.
set -euo pipefail
cd "$(dirname "$0")/../.."

tag=""
yes=false
check_only=false
for arg in "$@"; do
    case "$arg" in
        --yes) yes=true ;;
        --check-only) check_only=true ;;
        -*) echo "unknown flag $arg" >&2; exit 1 ;;
        *) [ -z "$tag" ] || { echo "give one tag" >&2; exit 1; }; tag="$arg" ;;
    esac
done
[ -n "$tag" ] || { echo "usage: sign-release.sh <tag> [--yes] [--check-only]" >&2; exit 1; }
repo="loupe-ac/loupe"
keyfile="$HOME/.config/loupe/release-signing.enc.yaml"

if [[ "$tag" =~ ^cli/v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    workflow="cli-release.yml"
    ref="refs/tags/$tag"
    track="cli/v*"
elif [[ "$tag" =~ ^skills/(loupe|loupe-lifecycle)/v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    workflow="skills-release.yml"
    ref="refs/heads/main"
    track="skills/${BASH_REMATCH[1]}/v*"
else
    echo "the tag must look like cli/v1.2.3 or skills/loupe/v1.2.3" >&2
    exit 1
fi
for tool in gh git jq sops ssh-add ssh-keygen; do
    command -v "$tool" >/dev/null || { echo "this recipe needs $tool" >&2; exit 1; }
done
if [ "$check_only" != true ]; then
    [ -f "$keyfile" ] || { echo "the signing key file $keyfile does not exist" >&2; exit 1; }
    [ -n "${SSH_AUTH_SOCK:-}" ] || { echo "SSH_AUTH_SOCK is not set, so there is no SSH agent" >&2; exit 1; }
fi

work="$(mktemp -d)"
cleanup() {
    if [ -f "$work/active.pub" ]; then ssh-add -d "$work/active.pub" >/dev/null 2>&1 || true; fi
    rm -rf "$work"
}
trap cleanup EXIT

cat > "$work/allowed_signers" <<'EOF'
# BEGIN release_keys
loupe-release ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAUeC5MaWHXeMaiqKi9xzbbVIBvclqrpBTYr8OdIQUOs
loupe-release ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ45ZR/m+TS0VjF4rHIbtWD5JcIcbqhW4TegaR0CuWZb
# END release_keys
EOF
# The first key line is the active key. ssh-keygen -Y sign wants it without the principal.
grep -v '^#' "$work/allowed_signers" | head -n 1 | cut -d ' ' -f 2- > "$work/active.pub"

draft="$(gh release view "$tag" --repo "$repo" --json isDraft,assets --jq '[.isDraft, ([.assets[].name] | index("checksums.txt.sig") != null)] | @tsv')"
[ "${draft%$'\t'*}" = true ] || { echo "$tag is not a draft release" >&2; exit 1; }
[ "${draft##*$'\t'}" = false ] || { echo "$tag already has a checksums.txt.sig" >&2; exit 1; }

# One local copy: it is checked, shown and signed. Nothing downloads it again.
gh release download "$tag" --repo "$repo" --pattern checksums.txt --dir "$work"
gh attestation verify "$work/checksums.txt" --repo "$repo" \
    --signer-workflow "$repo/.github/workflows/$workflow" --source-ref "$ref" \
    --deny-self-hosted-runners --format json > "$work/attestation.json"
git fetch --quiet origin main
commit="$(sh cli/scripts/sign-release-check.sh "$work/attestation.json" "$workflow" "$ref" origin/main)"

echo
echo "Release $tag, built by $workflow from commit $commit on main."
echo
cat "$work/checksums.txt"
echo
git fetch --quiet --tags origin
previous=""
for candidate in $(git tag --list "$track" --sort=-v:refname); do
    [ "$candidate" != "$tag" ] || continue
    if gh release view "$candidate" --repo "$repo" --json assets --jq '.assets[].name' 2>/dev/null | grep -qx checksums.txt.sig; then
        previous="$candidate"
        break
    fi
done
if [ -n "$previous" ]; then
    echo "Commits since $previous:"
    git log --oneline "$(git rev-list -n 1 "$previous")..$commit"
else
    echo "No earlier signed release of this track. The last 20 commits:"
    git log --oneline -n 20 "$commit"
fi
echo
if [ "$check_only" = true ]; then
    echo "check only: nothing was signed"
    exit 0
fi
if [ "$yes" != true ]; then
    read -r -p "Sign and publish $tag? [y/N] " answer < /dev/tty
    [ "$answer" = y ] || { echo "nothing was signed" >&2; exit 1; }
fi

sops decrypt --extract '["release_signing_key"]' "$keyfile" | ssh-add -t 60 -
ssh-keygen -Y sign -f "$work/active.pub" -n loupe-release "$work/checksums.txt"
ssh-keygen -Y verify -f "$work/allowed_signers" -I loupe-release -n loupe-release \
    -s "$work/checksums.txt.sig" < "$work/checksums.txt"
gh release upload "$tag" "$work/checksums.txt.sig" --repo "$repo"
gh release edit "$tag" --repo "$repo" --draft=false
if [ "$workflow" = cli-release.yml ]; then
    gh workflow run cli-homebrew.yml --repo "$repo" --ref main -f tag="$tag"
fi

