#!/bin/sh
# Decides whether the owner may sign a checksums.txt, from the JSON of
# "gh attestation verify --format json".
# Usage: sign-release-check.sh <attestation.json> <workflow file> <source ref> <main ref>
# On success it prints the attested commit. Otherwise it prints the reason to
# stderr and exits with status 1. Run it inside a clone that holds the main ref.
set -eu

json=$1
workflow=$2
ref=$3
main=$4

repo=loupe-ac/loupe

fail() {
	printf 'refused: %s\n' "$*" >&2
	exit 1
}

command -v jq >/dev/null 2>&1 || fail "this check needs jq."

# One tab-separated line for each attestation. A missing field becomes an empty one.
rows=$(jq -r '
	if type != "array" then error("not an array") else . end
	| .[]
	| .verificationResult.signature.certificate
	| [ (.githubWorkflowRepository // ""), (.sourceRepositoryURI // ""), (.buildSignerURI // ""),
	    (.sourceRepositoryRef // ""), (.sourceRepositoryDigest // ""), (.buildSignerDigest // ""),
	    (.runnerEnvironment // "") ]
	| @tsv' "$json") || fail "the attestation output is not the expected JSON."
[ -n "$rows" ] || fail "the output holds no attestation."

tab=$(printf '\t')
commit=''
while IFS="$tab" read -r wf_repo src_uri signer_uri src_ref src_digest signer_digest runner; do
	[ "$wf_repo" = "$repo" ] || fail "the run belongs to repository '$wf_repo', not $repo."
	[ "$src_uri" = "https://github.com/$repo" ] || fail "the source repository is '$src_uri'."
	want="https://github.com/$repo/.github/workflows/$workflow@$ref"
	[ "$signer_uri" = "$want" ] || fail "the signer workflow is '$signer_uri', not '$want'."
	[ "$src_ref" = "$ref" ] || fail "the source ref is '$src_ref', not '$ref'."
	[ "$runner" = github-hosted ] || fail "the run used a '$runner' runner."
	printf '%s' "$src_digest" | grep -Eq '^[0-9a-f]{40}$' || fail "the source commit '$src_digest' is not a commit id."
	[ "$src_digest" = "$signer_digest" ] || fail "the source commit $src_digest differs from the signer commit $signer_digest."
	[ -z "$commit" ] || [ "$commit" = "$src_digest" ] || fail "the attestations name more than one commit."
	commit=$src_digest
done <<EOT
$rows
EOT

git merge-base --is-ancestor "$commit" "$main" 2>/dev/null || fail "commit $commit is not an ancestor of $main."
printf '%s\n' "$commit"
