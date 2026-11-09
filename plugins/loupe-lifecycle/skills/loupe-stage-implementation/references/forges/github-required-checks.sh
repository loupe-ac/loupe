#!/usr/bin/env bash
# Prints the checks that Loupe waits for on a GitHub pull request, one name for each line.
# It takes the union of the required_status_checks of the rulesets (rules/branches/<base>) and of the classic branch
# protection (branches/<base>), and drops "Loupe / Approval", which github-approval-covers.sh reads.
# When that union names no CI check, the script prints the line EVERY_NON_LOUPE_CHECK. Then every check on the
# head that Loupe did not post must pass. GitHub can refuse the rules on a plan without rulesets ("Upgrade to
# GitHub ..."). The script then prints the list of --required-checks, and an empty list prints no name, which means no
# CI gate. Without --required-checks it prints EVERY_NON_LOUPE_CHECK.
# --required-checks takes the JSON array that workflow_get returns as the required-checks setting, or "null" when unset.
# Usage: github-required-checks.sh [--base <branch>] [--required-checks <json>] <pull request url>. Run it from a checkout of the repository.
# --base reads the rules of another branch than the base of the pull request. An epic child needs the base branch of the repository.
# Prints the names and exits 0, also with no name. Prints UNREAD with the reason and exits 2 when a read fails.
set -o pipefail
usage="usage: github-required-checks.sh [--base <branch>] [--required-checks <json>] <pull request url>"
base=
list=null
while [ $# -gt 0 ]; do
  case "$1" in
    --base) base=${2:-}; [ -n "$base" ] || { echo "UNREAD: --base takes a branch"; exit 2; }; shift 2 ;;
    --required-checks) list=${2:-null}; shift 2 ;;
    *) break ;;
  esac
done
jq -e 'type == "null" or (type == "array" and all(.[]; type == "string" and . != ""))' <<<"$list" >/dev/null 2>&1 || { echo "UNREAD: --required-checks is not a list of names"; exit 2; }
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
  refused=1
  rules='[]'
else
  echo "UNREAD: rules read failed"; exit 2
fi
jq -e type <<<"$rules" >/dev/null 2>&1 || { echo "UNREAD: rules read failed"; exit 2; }
branch=$(gh api "repos/$repo/branches/$enc" 2>/dev/null) || { echo "UNREAD: branch read failed"; exit 2; }
jq -e type <<<"$branch" >/dev/null 2>&1 || { echo "UNREAD: branch read failed"; exit 2; }

jq -rn --argjson rules "$rules" --argjson branch "$branch" --argjson list "$list" --argjson refused "${refused:-0}" '
  ([$rules[]|select(.type=="required_status_checks")|.parameters.required_status_checks[]?|.context]
   + [$branch.protection.required_status_checks // {} | ((.contexts // []) + [.checks[]?|.context])[]])
  | unique | map(select(. != "Loupe / Approval")) as $github
  | if ($github | map(select(startswith("Loupe / ") | not)) | length) > 0 then $github
    elif $refused == 1 and $list != null then $github + $list
    else $github + ["EVERY_NON_LOUPE_CHECK"] end
  | map(select(. != "Loupe / Approval")) | unique | .[]'
