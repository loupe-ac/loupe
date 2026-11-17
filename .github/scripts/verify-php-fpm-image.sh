#!/usr/bin/env bash
# Prints the digest of an image that a run of ci.yml on main signed. Exits 1 for any other image.
set -euo pipefail
image=$1
digest=$(docker buildx imagetools inspect "$image" --format '{{.Manifest.Digest}}')
[[ $digest =~ ^sha256:[0-9a-f]{64}$ ]]
gh attestation verify "oci://${image%%:*}@$digest" \
  --repo "$GITHUB_REPOSITORY" \
  --signer-workflow "$GITHUB_REPOSITORY/.github/workflows/ci.yml" \
  --source-ref refs/heads/main >&2
echo "$digest"
