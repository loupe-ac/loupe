# GitHub adapter

The stage skills read this file when the forge is `github`. It maps each forge operation to `gh` commands. Run them from a checkout of the repository.

`<n>` is the pull request number. `<nameWithOwner>` is the repository of the checkout, such as `loupe-ac/loupe`. Split it into `<owner>` and `<repo>` where a command needs both.

## Get a token

The bridge gives a worker GitHub credentials that end. Git and `gh` ask the bridge for them, so a plain `git push` and a plain `gh` command work. When you need a bare token for another call, run `loupe credential` each time you need one. Never store it, in a file, a variable, or a comment. When `loupe credential` fails, report its stderr line and stop. Do not try another credential.

## Find and validate the pull request

Run this before any other query or reply:

```bash
gh repo view --json nameWithOwner
gh api graphql -F url=<url> -f query='query($url:URI!){resource(url:$url){... on PullRequest{number state headRefName headRefOid isCrossRepository baseRepository{nameWithOwner}}}}'
```

`state` is `OPEN`, `CLOSED` or `MERGED`. Report state: `open`, `closed` or `merged`. The head branch is `headRefName`, and the head commit is `headRefOid`. When `baseRepository.nameWithOwner` differs from the checkout, or `isCrossRepository` is `true`, the pull request is outside this repository.

## List the feedback items

```bash
gh api graphql --paginate -F owner='<owner>' -F repo='<repo>' -F n=<n> -f query='query($owner:String!,$repo:String!,$n:Int!,$endCursor:String){repository(owner:$owner,name:$repo){pullRequest(number:$n){reviewThreads(first:100,after:$endCursor){pageInfo{hasNextPage endCursor} nodes{id isResolved comments(first:100){pageInfo{hasNextPage} nodes{databaseId author{login} body}}}}}}}'
gh api repos/<nameWithOwner>/pulls/<n>/reviews --paginate
gh api repos/<nameWithOwner>/issues/<n>/comments --paginate
```

1. A review thread is a node of `reviewThreads`. Its id is the node `id`, and it is resolved when `isResolved` is `true`.
2. A review body is the `body` of an entry in the reviews list. Its id is the review `id`.
3. A top-level comment is an entry in the issue comments list. Its id is the comment `id`.
4. `--paginate` walks every page of threads. A thread keeps its first 100 comments. When `comments.pageInfo.hasNextPage` is `true`, the thread is too long to read.

## Reply to a thread

Take the `databaseId` of the first comment in the thread:

```bash
gh api repos/<nameWithOwner>/pulls/<n>/comments/<databaseId>/replies -f body='<!-- loupe-stage-worker -->
Addressed thread <id>: <what changed, commits>'
```

## Post a top-level comment

```bash
gh api repos/<nameWithOwner>/issues/<n>/comments -f body='<!-- loupe-stage-worker -->
Addressed review <id>: <what changed, commits>'
```

## Post a refusal comment

The head commit is `headRefOid` from "Find and validate the pull request". List the bodies of the top-level comments:

```bash
gh api repos/<nameWithOwner>/issues/<n>/comments --paginate --jq '.[].body'
```

When the command fails, the post fails. Otherwise search its output for the marker line with `grep -F`. A match means the comment exists, so post nothing. With no match, write the body to a file with a file tool, not with the shell, because a reason can hold a quote. The first line is the marker, and the second line is `<reason>. <next step>`. Then post the comment:

```bash
gh api repos/<nameWithOwner>/issues/<n>/comments -F body=@<body file>
```

## Read the checks

Read the checks once, and never wait for them. The app reads them again after each push.

```bash
gh pr view <url> --json headRefOid -q .headRefOid
gh pr checks <url> --required --json name,bucket,link
```

`bucket` is `pass`, `fail`, `pending`, `skipping` or `cancel`. The reading describes the head only when `headRefOid` equals the commit you care about. A rollup can still describe an earlier head, when a check has not yet started on the new head. The repository profile says where the list of required checks comes from.

A stacked pull request has no required checks when no ruleset covers its base. For it, drop `--required`, and count every check. `--required` fails there with "No required checks reported".

A pull request into an epic branch takes the required checks of the default branch of the repository, by name. Read the names, then every check:

```bash
gh api repos/<nameWithOwner>/rules/branches/<default branch> --jq '.[]|select(.type=="required_status_checks")|.parameters.required_status_checks[].context'
gh pr checks <url> --json name,bucket,link
```

Read the JSON whatever the exit code. Each name must have a check with the `bucket` `pass`. A name with no check counts as pending.

A check's `link` holds `/actions/runs/<run id>/`. Read the failed steps of that run:

```bash
gh run view <run id> --log-failed
```

## Create a pull request

```bash
git push -u origin HEAD
gh pr list --head <branch> --state open --json url
gh pr create --base <base> --title "<title>" --body-file <file> --draft
```

Link a pull request that `gh pr list` returns, and create none. Always pass `--draft`.

A branch that the checkout is not on, such as an epic branch, takes another form. Skip the push, and name the head:

```bash
gh pr list --head <branch> --base <base> --state open --json url
gh pr create --head <branch> --base <base> --title "<title>" --body-file <file> --draft
```

When `gh pr create` fails because the pull request already exists, run `gh pr list` again, and link the one it returns. GitHub refuses a pull request with no commits between the two branches.

## Read the pull request body

```bash
gh pr view <url> --json body -q .body
```

## Replace the pull request body

```bash
gh pr edit <url> --body-file <file>
```

The new body replaces the whole body. Read it first, change only your part, and write the rest back unchanged.

## Compare with the base

```bash
gh api repos/<nameWithOwner>/compare/<base>...<head sha> --jq .behind_by
```

`behind_by` counts the commits of the base that the head does not hold. A value above 0 means the branch is behind. When no strict rule covers the base, GitHub reports `CLEAN` for a behind pull request. Read the compare in that case.

Report behind count: `behind_by`.

## Check mergeability

```bash
gh pr view <url> --json mergeable,mergeStateStatus
```

`mergeable` is `MERGEABLE`, `CONFLICTING` or `UNKNOWN`. `UNKNOWN` means GitHub still computes it, so read it again after a short wait.

Report mergeability, as the next section says.

## Read the merge state

```bash
gh pr view <url> --json state,isDraft,headRefOid,baseRefName,mergeable,mergeStateStatus
<forge dir>/github-required-checks.sh <url>
gh pr checks <url> --json name,bucket,link
```

`<forge dir>` is the directory of this file. The second command prints the names of the required checks of the base branch, one for each line. It reads the rulesets and the classic branch protection live, so no profile count can go stale. It leaves out `Loupe / Approval`, which "Check the approval covers the head" reads. It exits 2 and prints `UNREAD` with a reason when a read fails. An epic child passes `--base <default branch>` with the default branch of the repository, because an epic branch has no rules of its own.

The third command lists every check of the head. Read its JSON whatever the exit code. Each required name must have a check with the `bucket` `pass`. A name with no check counts as pending, also when the check has not started yet. An empty list of names is green.

The approval is not part of this read. "Check the approval covers the head" reads it. `mergeStateStatus` is `CLEAN`, `HAS_HOOKS`, `UNSTABLE`, `BEHIND`, `BLOCKED`, `DIRTY` or `UNKNOWN`. Green means that every required name passes, and that none fails, waits or has no check.

Report state: `OPEN` is `open`, `MERGED` is `merged`, and `CLOSED` is `closed`.

Report draft: `isDraft` `true` is `yes`, and `false` is `no`.

Report checks: `passed` when green. `failed` when a required name has a check in the `fail` or `cancel` bucket. Otherwise `pending`. When the second command prints `UNREAD`, stop the merge with `UNREAD: <reason>`, and report no checks.

Report mergeability, in this order:

1. `conflicting` when `mergeable` is `CONFLICTING` or `mergeStateStatus` is `DIRTY`.
2. `behind` when `mergeStateStatus` is `BEHIND`.
3. `blocked` when `mergeStateStatus` is `BLOCKED`.
4. `unknown` when `mergeable` is `UNKNOWN`.
5. `mergeable` when `mergeStateStatus` is `CLEAN`, `HAS_HOOKS` or `UNSTABLE`.
6. `unknown` otherwise.

## Check the approval covers the head

The approval is the check `Loupe / Approval` from the Loupe App, plus whatever the rules of the base branch add: a number of approving reviews. `reviewDecision` is no source for the approval, because it stays empty when the rules require a check and no review, and it stays `APPROVED` after a push when the rules do not dismiss a stale review. The script reads it only for `REVIEW_REQUIRED`, which means GitHub wants more approvals. Read the approval with the script that sits next to this file. `<forge dir>` is the directory of this file:

```bash
APPROVER=<login> <forge dir>/github-approval-covers.sh --conflicts <covered|hold> --app-id <githubAppId> <url> <sha>
```

Set `--conflicts` from the project rule: `hold` when `workflow_get` returns `approvalCoversConflictResolution` false, and `covered` otherwise. A flag left out means `covered`.

Set `--app-id` to `githubAppId` from `workflow_get`. The script needs it when no rule names the check. When `githubAppId` is null, omit the flag. The script then stops with `UNREAD` in that case.

Set `APPROVER` when the instruction files of the repository name an approver. The named reviewer must then also hold a current approval that covers the head. This adds a condition and lowers no count that the rules require. Otherwise omit it.

The script reads `repos/<nameWithOwner>/rules/branches/<base>`, which lists the active rules of every ruleset that applies:

1. A rule that requires the check `Loupe / Approval` selects the App that may post it. The script needs a successful run of that check on the head commit from each selected App. A run of the same name from another App does not count. A check belongs to one commit, so a success on the head covers the head. When a rule selects no App, the script holds.
2. A rule that requires approving reviews gives a count. GitHub enforces the largest count of all the rules, and so does the script. It takes each reviewer's last approving or blocking review, and needs that many current approvals. Each of the newest approvals must cover the head, by time. The script reads the pushes to the head branch, and takes the head of the last push before the approval. A commit in that head is covered. The script sorts every other commit. A review's `commit_id` does not show what the reviewer saw, because GitHub moves it onto the head that a later merge creates.
3. When the rules require both, both must hold.
4. When no rule names the check, the script needs a successful `Loupe / Approval` run from the App with the id of `--app-id` on the head. So with no rules, the merge stage merges on the same signal as the app: the agent review passed. A review count that the rules name still applies.
5. When GitHub answers `403` with a message that starts `Upgrade to GitHub` (a plan with no rulesets), the script reads no rules. Any other `403` is a lost permission and gives `UNREAD`.
6. When `reviewDecision` is `REVIEW_REQUIRED`, rulesets or classic branch protection want more approvals, and the script holds. This read needs no admin permission. `branches/<base>/protection` does.

The script prints one line:

| Line | Exit | Meaning |
|---|---|---|
| `COVERED ...` | 0 | The rules hold. Every later commit of a counted review is a merge from the base. With `--conflicts hold`, git also makes each merge again with no conflict. |
| `HOLD commits after approval: ...` | 1 | A later commit adds new content. |
| `HOLD conflict resolution after approval: ...` | 1 | With `--conflicts hold`, a merge from the base holds a conflict resolution or a hand edit. |
| `HOLD no approval check on the head` | 1 | The Loupe App, or the App that a rule selects, posted no success on the head. |
| `HOLD GitHub requires more approvals` | 1 | `reviewDecision` is `REVIEW_REQUIRED`. |
| `HOLD the rules select no App for Loupe / Approval` | 1 | A rule requires the check and selects no App, so the script cannot tell a forged run from a real one. |
| `HOLD <found> of <count> approvals` | 1 | The rules require more current approvals than exist. |
| `HOLD no approval from <login>` | 1 | The named approver holds no current approval. |
| `HOLD head moved`, `HOLD no push ...`, `HOLD 250 commits or more ...` | 1 | The item fails for that reason. |
| `UNREAD: ...` | 2 | A read failed, or no rule names the check and `--app-id` is missing. |

Report approval: the script line is the report, as it is.

A rebase or a force push rewrites commits, so they hold as commits after approval.

## Update the branch

```bash
gh pr update-branch <url>
```

This merges the base into the head branch on the forge. Never pass `--rebase`. Whether an update keeps an approval depends on the branch rules, and the rules of the repository say it.

## Change the base

The base of a stacked pull request is the head branch of its parent. Find the merged parent, and read its base:

```bash
gh pr view <url> --json baseRefName -q .baseRefName
gh pr list --head <parent head branch> --state merged --json url,baseRefName
```

An empty list means no parent merged. Then change the base to the `baseRefName` of the parent:

```bash
gh pr edit <url> --base <parent base>
```

## Merge a branch into the epic branch

```bash
git ls-remote --exit-code origin refs/heads/<epic branch>
gh api repos/<nameWithOwner>/merges -f base='<epic branch>' -f head='<base>' -i
```

A non-zero exit of `git ls-remote` means the epic branch does not exist. The forge makes the merge commit on the epic branch. The status line reports the result: `201` merged, `204` already up to date, and `409` a conflict. Any other status is a failed step.

## Read the rules of the base branch

These commands only read.

```bash
gh api repos/<nameWithOwner>/rules/branches/<base>
gh repo view <nameWithOwner> --json mergeCommitAllowed,squashMergeAllowed,rebaseMergeAllowed
gh api repos/<nameWithOwner>/branches/<base>/protection
```

The first command lists the active rules by `type`. `pull_request` holds `required_approving_review_count` and `allowed_merge_methods`. `required_status_checks` names the required checks. `non_fast_forward` forbids a force push, `deletion` forbids deletion, and `merge_queue` is a merge queue. The second command gives the repository switches.

The third command reads classic branch protection. The message `Branch not protected` means that the branch has none. Any other error, such as `Not Found` with no admin permission, means that it could not be read.

Report each setting that a command fails to read as not readable, and quote the error.

The first command answers `403` with the message "Upgrade to GitHub Pro or make this repository public to enable this feature." on a private repository of a plan with no rulesets. Read that answer as "GitHub enforces no rules". Any other `403` is a lost permission.

## Merge

```bash
gh pr merge <url> --<method> --match-head-commit <sha>
```

`<method>` comes from the rules of the repository. For an epic child it is the `epic-child-merge-method` setting that `workflow_get` returns for the repository of the pull request: `squash`, `merge` or `rebase`. `--match-head-commit` refuses the merge when the head moved. Never pass `--admin` or `--auto`. On a branch with a merge queue, `gh pr merge` turns on auto-merge by itself, so the state stays `OPEN`. After the merge, read `state` again, and accept only `MERGED`, which is the state `merged`.
