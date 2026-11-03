#!/usr/bin/env bash
# Tells whether a GitHub pull request is approved, as the rules of its base branch require, up to <sha>.
# The rules can require the check "Loupe / Approval" from a GitHub App, a number of approving reviews, or both.
# With neither, one current approval is required, because this script reads rulesets only.
# Usage: github-approval-covers.sh [--conflicts covered|hold] <pull request url> <sha>. Run it from a checkout of the repository.
# --conflicts covered (the default) lets a review cover a merge from the base that holds a conflict resolution.
# --conflicts hold holds such a merge. It is the value false of approvalCoversConflictResolution in workflow_get.
# Prints COVERED, or HOLD with the reason. Exits 0 on COVERED, 1 on HOLD, 2 when a read fails.
# APPROVER=<login> adds a condition: that reviewer must hold a current approval that covers the head. It lowers no count.
# AS_OF=<ISO time> ignores later reviews, to replay a decision.
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

check_name='Loupe / Approval'
rules=$(gh api "repos/$repo/rules/branches/$(jq -rn --arg b "$base" '$b|@uri')" 2>/dev/null) || { echo "UNREAD: rules read failed"; exit 2; }
jq -e type <<<"$rules" >/dev/null 2>&1 || { echo "UNREAD: rules read failed"; exit 2; }
# GitHub combines every ruleset that applies to the branch and enforces the most restrictive review count.
need=$(jq -r '[.[]|select(.type=="pull_request")|.parameters.required_approving_review_count // 0]|max // 0' <<<"$rules")
named=$(jq -r --arg n "$check_name" '[.[]|select(.type=="required_status_checks")|.parameters.required_status_checks[]?|select(.context==$n)]|length' <<<"$rules")
apps=$(jq -c --arg n "$check_name" '[.[]|select(.type=="required_status_checks")|.parameters.required_status_checks[]?|select(.context==$n)|.integration_id]|unique' <<<"$rules")

result=
if [ "$named" -gt 0 ]; then
  jq -e 'index(null) == null' <<<"$apps" >/dev/null || { echo "HOLD the rules select no App for $check_name"; exit 1; }
  runs=$(gh api --paginate "repos/$repo/commits/$sha/check-runs?check_name=$(jq -rn --arg n "$check_name" '$n|@uri')&per_page=100" 2>/dev/null | jq -sc '[.[].check_runs[]|{app:.app.id,slug:.app.slug,conclusion,sha:.head_sha}]') || { echo "UNREAD: check run list failed"; exit 2; }
  jq -e --arg sha "$sha" --argjson apps "$apps" '. as $r | $apps | all(. as $id | $r | any(.app == $id and .conclusion == "success" and .sha == $sha))' <<<"$runs" >/dev/null || { echo "HOLD no approval check on the head"; exit 1; }
  result="check=$(jq -r --argjson apps "$apps" '[.[]|select(.app|IN($apps[]))|.slug]|unique|join(",")' <<<"$runs")"
  [ "$need" -gt 0 ] || [ -n "${APPROVER:-}" ] || { echo "COVERED $result head=${head:0:8}"; exit 0; }
elif [ "$need" -eq 0 ]; then
  need=1
fi

# Each reviewer's last approving or blocking review counts. The current approvals sort newest first.
approvals=$(jq -c --arg asof "${AS_OF:-9999}" '[.[]|select(.at <= $asof and (.state|IN("APPROVED","CHANGES_REQUESTED","DISMISSED")))]
  | group_by(.login) | map(max_by(.at)) | map(select(.state == "APPROVED")) | sort_by(.at) | reverse' <<<"$reviews")
found=$(jq length <<<"$approvals")
[ "$found" -ge "$need" ] || { echo "HOLD $found of $need approvals"; exit 1; }
counted=$(jq -c --argjson n "$need" '.[:$n]' <<<"$approvals")
if [ -n "${APPROVER:-}" ]; then
  mine=$(jq -c --arg who "$APPROVER" '[.[]|select(.login == $who)]' <<<"$approvals")
  [ "$(jq length <<<"$mine")" -gt 0 ] || { echo "HOLD no approval from $APPROVER"; exit 1; }
  counted=$(jq -c --argjson m "$mine" '. + ($m - .) | unique_by(.login)' <<<"$counted")
fi

pushes=$(gh api --paginate "repos/$repo/activity?ref=refs/heads/$branch&per_page=100" 2>/dev/null | jq -sc 'add // []') || { echo "UNREAD: push list failed"; exit 2; }
git fetch -q origin "$base" "refs/pull/$pr/head" 2>/dev/null || { echo "UNREAD: git fetch failed"; exit 2; }

# The reviewer saw the branch as the last push before the approval left it. Commit dates can be older than the push.
sync=0
for at in $(jq -r '.[].at' <<<"$counted"); do
  seen=$(jq -r --arg at "$at" '[.[]|select(.timestamp < $at)]|max_by(.timestamp)|.after // ""' <<<"$pushes")
  [ -n "$seen" ] || { echo "HOLD no push of $branch before the approval"; exit 1; }
  new=() resolved=() n=0
  while IFS=$'\t' read -r oid parents headline; do
    [ -n "$oid" ] || continue
    git merge-base --is-ancestor "$oid" "$seen" 2>/dev/null && continue
    if [ "$parents" -gt 1 ] && git merge-base --is-ancestor "$oid^2" "origin/$base" 2>/dev/null; then
      # With --conflicts hold, a merge counts as a sync only when git makes the same tree again with no conflict.
      if [ "$conflicts" = hold ] && [ "$(git merge-tree --write-tree "$oid^1" "$oid^2" 2>/dev/null)" != "$(git rev-parse "$oid^{tree}")" ]; then
        resolved+=("${oid:0:8}")
        continue
      fi
      n=$((n + 1))
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
  sync=$n
done
echo "COVERED ${result:+$result }approvals=$(jq length <<<"$counted") newest=$(jq -r '.[0].at' <<<"$counted") head=${head:0:8} sync-merges-after-approval=$sync"
