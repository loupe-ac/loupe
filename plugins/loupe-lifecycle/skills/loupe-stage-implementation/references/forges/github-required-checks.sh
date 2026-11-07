#!/usr/bin/env bash
# Prints the names of the checks that the base branch of a GitHub pull request requires, one for each line.
# It takes the union of the required_status_checks of the rulesets (rules/branches/<base>) and of the classic branch
# protection (branches/<base>), and drops "Loupe / Approval", which github-approval-covers.sh reads.
# GitHub can refuse the rules on a plan without rulesets ("Upgrade to GitHub ..."). The script then reads no rulesets.
# Usage: github-required-checks.sh [--base <branch>] <pull request url>. Run it from a checkout of the repository.
# --base reads the rules of another branch than the base of the pull request. An epic child needs the base branch of the repository.
# Prints the names and exits 0, also with no name. Prints UNREAD with the reason and exits 2 when a read fails.
set -o pipefail
usage="usage: github-required-checks.sh [--base <branch>] <pull request url>"
base=
if [ "${1:-}" = "--base" ]; then
  base=${2:-}
  [ -n "$base" ] || { echo "UNREAD: --base takes a branch"; exit 2; }
  shift 2
fi
url=${1:?$usage}
[[ $url =~ ^https://([^/]+)/([^/]+)/([^/]+)/pull/([0-9]+)/?$ ]] || { echo "UNREAD: not a GitHub pull request URL: $url"; exit 2; }
host=${BASH_REMATCH[1]} repo="${BASH_REMATCH[2]}/${BASH_REMATCH[3]}"
auth=$(gh auth status --hostname "$host" 2>&1) || { echo "UNREAD: gh auth status failed for $host: $(head -n1 <<<"$auth")"; exit 2; }
export GH_HOST=$host

if [ -z "$base" ]; then
  base=$(gh pr view "$url" --json baseRefName -q .baseRefName 2>/dev/null) || { echo "UNREAD: gh pr view failed"; exit 2; }
fi
[ -n "$base" ] || { echo "UNREAD: no base branch"; exit 2; }
enc=$(jq -rn --arg b "$base" '$b|@uri')

if rules=$(gh api "repos/$repo/rules/branches/$enc" 2>&1); then
  :
elif grep -Eq '^gh: Upgrade to GitHub.*\(HTTP 403\)|"message": ?"Upgrade to GitHub' <<<"$rules"; then
  rules='[]'
else
  echo "UNREAD: rules read failed"; exit 2
fi
jq -e type <<<"$rules" >/dev/null 2>&1 || { echo "UNREAD: rules read failed"; exit 2; }
branch=$(gh api "repos/$repo/branches/$enc" 2>/dev/null) || { echo "UNREAD: branch read failed"; exit 2; }
jq -e type <<<"$branch" >/dev/null 2>&1 || { echo "UNREAD: branch read failed"; exit 2; }

jq -rn --argjson rules "$rules" --argjson branch "$branch" '
  ([$rules[]|select(.type=="required_status_checks")|.parameters.required_status_checks[]?|.context]
   + [$branch.protection.required_status_checks // {} | ((.contexts // []) + [.checks[]?|.context])[]])
  | unique | map(select(. != "Loupe / Approval")) | .[]'
