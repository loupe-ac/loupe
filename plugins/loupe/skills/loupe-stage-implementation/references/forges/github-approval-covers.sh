#!/usr/bin/env bash
# Tells whether the current approvals of a GitHub pull request cover every commit up to <sha>.
# Usage: github-approval-covers.sh [--conflicts covered|hold] <pull request url> <sha>. Run it from a checkout of the repository.
# --conflicts covered (the default) lets an approval cover a merge from the base that holds a conflict resolution.
# --conflicts hold holds such a merge. It is the value false of approvalCoversConflictResolution in workflow_get.
# Prints COVERED, or HOLD with the reason. Exits 0 on COVERED, 1 on HOLD, 2 when a read fails.
# APPROVER=<login> counts that reviewer only. AS_OF=<ISO time> ignores later reviews, to replay a decision.
set -o pipefail
usage="usage: github-approval-covers.sh [--conflicts covered|hold] <pull request url> <sha>"
conflicts=covered
if [ "${1:-}" = "--conflicts" ]; then
  conflicts=${2:-}
  [ "$conflicts" = covered ] || [ "$conflicts" = hold ] || { echo "UNREAD: --conflicts takes covered or hold"; exit 2; }
  shift 2
fi
url=${1:?$usage}
sha=${2:?$usage}
[[ $url =~ ^https://([^/]+)/([^/]+)/([^/]+)/pull/([0-9]+)/?$ ]] || { echo "UNREAD: not a GitHub pull request URL: $url"; exit 2; }
host=${BASH_REMATCH[1]} repo="${BASH_REMATCH[2]}/${BASH_REMATCH[3]}" pr=${BASH_REMATCH[4]}
auth=$(gh auth status --hostname "$host" 2>&1) || { echo "UNREAD: gh auth status failed for $host: $(head -n1 <<<"$auth")"; exit 2; }
export GH_HOST=$host

view=$(gh pr view "$url" --json headRefOid,headRefName,baseRefName 2>/dev/null) || { echo "UNREAD: gh pr view failed"; exit 2; }
head=$(jq -r .headRefOid <<<"$view") branch=$(jq -r .headRefName <<<"$view") base=$(jq -r .baseRefName <<<"$view")
[ "$head" = "$sha" ] || { echo "HOLD head moved: ${head:0:8}, not ${sha:0:8}"; exit 1; }
commits=$(gh api --paginate "repos/$repo/pulls/$pr/commits?per_page=100" 2>/dev/null | jq -sc '[.[][]|{oid:.sha,parents:(.parents|length),headline:(.commit.message|split("\n")[0])}]') || { echo "UNREAD: commit list failed"; exit 2; }
reviews=$(gh api --paginate "repos/$repo/pulls/$pr/reviews?per_page=100" 2>/dev/null | jq -sc '[.[][]|{login:.user.login,state,at:.submitted_at}]') || { echo "UNREAD: review list failed"; exit 2; }
[ "$(jq length <<<"$commits")" -lt 250 ] || { echo "HOLD 250 commits or more, GitHub truncates the list"; exit 1; }

# Each reviewer's last approving or blocking review counts. The earliest current approval sets the time.
at=$(jq -r --arg who "${APPROVER:-}" --arg asof "${AS_OF:-9999}" '[.[]|select(.at <= $asof and ($who == "" or .login == $who)
  and (.state|IN("APPROVED","CHANGES_REQUESTED","DISMISSED")))]
  | group_by(.login) | map(max_by(.at)) | map(select(.state == "APPROVED") | .at) | min // ""' <<<"$reviews")
[ -n "$at" ] || { echo "HOLD no approval${APPROVER:+ from $APPROVER}"; exit 1; }

# The reviewer saw the branch as the last push before the approval left it. Commit dates can be older than the push.
pushes=$(gh api --paginate "repos/$repo/activity?ref=refs/heads/$branch&per_page=100" 2>/dev/null | jq -sc 'add // []') || { echo "UNREAD: push list failed"; exit 2; }
seen=$(jq -r --arg at "$at" '[.[]|select(.timestamp < $at)]|max_by(.timestamp)|.after // ""' <<<"$pushes")
[ -n "$seen" ] || { echo "HOLD no push of $branch before the approval"; exit 1; }

git fetch -q origin "$base" "refs/pull/$pr/head" 2>/dev/null || { echo "UNREAD: git fetch failed"; exit 2; }
sync=0 new=() resolved=()
while IFS=$'\t' read -r oid parents headline; do
  [ -n "$oid" ] || continue
  git merge-base --is-ancestor "$oid" "$seen" 2>/dev/null && continue
  if [ "$parents" -gt 1 ] && git merge-base --is-ancestor "$oid^2" "origin/$base" 2>/dev/null; then
    # With --conflicts hold, a merge counts as a sync only when git makes the same tree again with no conflict.
    if [ "$conflicts" = hold ] && [ "$(git merge-tree --write-tree "$oid^1" "$oid^2" 2>/dev/null)" != "$(git rev-parse "$oid^{tree}")" ]; then
      resolved+=("${oid:0:8}")
      continue
    fi
    sync=$((sync + 1))
  else
    new+=("${oid:0:8} $headline")
  fi
done < <(jq -r '.[]|[.oid,(.parents|tostring),.headline]|@tsv' <<<"$commits")

if [ ${#new[@]} -gt 0 ]; then
  list=$(printf '%s; ' "${new[@]}"); echo "HOLD commits after approval: ${list%; }"; exit 1
fi
if [ ${#resolved[@]} -gt 0 ]; then
  list=$(printf '%s, ' "${resolved[@]}"); echo "HOLD conflict resolution after approval: ${list%, }"; exit 1
fi
echo "COVERED approval=$at seen=${seen:0:8} head=${head:0:8} sync-merges-after-approval=$sync"
