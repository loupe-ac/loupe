#!/usr/bin/env bash
# Signs the drafts of a whole release: the skills packages first, then the CLI.
# Usage: sign-all.sh <cli tag> [--yes]
# It checks every draft before it signs any draft, asks once on the terminal
# (never with --yes), and stops at the first refusal. The attestation checks of
# sign-release.sh run in every mode.
set -euo pipefail
cd "$(dirname "$0")/../.."

repo="loupe-ac/loupe"
signer="bash cli/scripts/sign-release.sh"
packages="loupe loupe-lifecycle"

cli_tag=""
yes=false
for arg in "$@"; do
    case "$arg" in
        --yes) yes=true ;;
        -*) echo "unknown flag $arg" >&2; exit 1 ;;
        *) [ -z "$cli_tag" ] || { echo "give one CLI tag" >&2; exit 1; }; cli_tag="$arg" ;;
    esac
done
[[ "$cli_tag" =~ ^cli/v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: sign-all.sh cli/v1.2.3 [--yes]" >&2; exit 1; }
for tool in gh git jq ssh-keygen; do
    command -v "$tool" >/dev/null || { echo "this script needs $tool" >&2; exit 1; }
done

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

refuse() {
    echo "refused: $*" >&2
    exit 1
}

# Checks that a published release carries a checksums.txt.sig that a release key made.
verify_published() {
    local tag="$1" dir
    dir="$work/pub-${tag//\//_}"
    mkdir -p "$dir"
    gh release download "$tag" --repo "$repo" --pattern checksums.txt --pattern checksums.txt.sig --dir "$dir" >/dev/null 2>&1 \
        || refuse "$tag is published but its checksums.txt or checksums.txt.sig cannot be downloaded."
    [ -s "$dir/checksums.txt.sig" ] || refuse "$tag is published with no checksums.txt.sig."
    ssh-keygen -Y verify -f cli/internal/update/release_keys -I loupe-release -n loupe-release \
        -s "$dir/checksums.txt.sig" < "$dir/checksums.txt" >/dev/null 2>&1 \
        || refuse "$tag is published and its checksums.txt.sig does not verify against the release keys."
}

# Prints "draft", "published" or "absent".
release_state() {
    local is_draft
    if ! is_draft="$(gh release view "$1" --repo "$repo" --json isDraft --jq .isDraft 2>/dev/null)"; then
        echo absent
    elif [ "$is_draft" = true ]; then
        echo draft
    else
        echo published
    fi
}

git fetch --quiet --tags origin

# The commit of the CLI tag comes from the attestation of its draft or release.
case "$(release_state "$cli_tag")" in
    absent) refuse "there is no release for $cli_tag." ;;
esac
gh release download "$cli_tag" --repo "$repo" --pattern checksums.txt --dir "$work" --clobber
gh attestation verify "$work/checksums.txt" --repo "$repo" \
    --signer-workflow "$repo/.github/workflows/cli-release.yml" --source-ref "refs/tags/$cli_tag" \
    --deny-self-hosted-runners --format json > "$work/cli-attestation.json" \
    || refuse "the attestation of $cli_tag does not verify."
cli_commit="$(jq -r '.[0].verificationResult.signature.certificate.sourceRepositoryDigest // empty' "$work/cli-attestation.json")"
[[ "$cli_commit" =~ ^[0-9a-f]{40}$ ]] || refuse "the attestation of $cli_tag names no commit."

drafts="$(gh api "repos/$repo/releases" --paginate --jq '.[] | select(.draft) | .tag_name')"

to_sign=()
for package in $packages; do
    version="$(git show "$cli_commit:plugins/$package/.claude-plugin/plugin.json" | jq -r .version)"
    [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || refuse "the version of $package at $cli_commit is '$version'."
    expected="skills/$package/v$version"
    package_drafts="$(printf '%s\n' "$drafts" | grep -x "skills/$package/v.*" || true)"
    [ "$(printf '%s' "$package_drafts" | grep -c .)" -le 1 ] || refuse "$package has more than one draft: $(echo $package_drafts)."
    if [ -n "$package_drafts" ] && [ "$package_drafts" != "$expected" ]; then
        refuse "the draft $package_drafts is not the expected release $expected."
    fi
    case "$(release_state "$expected")" in
        draft) to_sign+=("$expected") ;;
        published) verify_published "$expected"; echo "$expected is published and signed." ;;
        absent) refuse "$expected has no release and no draft. Its skills release failed or never started." ;;
    esac
done

case "$(release_state "$cli_tag")" in
    draft) to_sign+=("$cli_tag") ;;
    published) verify_published "$cli_tag"; echo "$cli_tag is published and signed." ;;
esac

if [ "${#to_sign[@]}" -eq 0 ]; then
    echo "nothing to sign"
    exit 0
fi

for tag in "${to_sign[@]}"; do
    $signer "$tag" --check-only
done

if [ "$yes" != true ]; then
    read -r -p "Sign and publish these ${#to_sign[@]} releases (${to_sign[*]})? [y/N] " answer < /dev/tty
    [ "$answer" = y ] || { echo "nothing was signed" >&2; exit 1; }
fi

for tag in "${to_sign[@]}"; do
    $signer "$tag" --yes
done
