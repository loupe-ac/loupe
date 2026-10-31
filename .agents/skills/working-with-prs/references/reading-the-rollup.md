# Reading the raw check rollup

Read this file when you parse `gh pr view --json statusCheckRollup` instead of
`gh pr checks`. `SKILL.md` "Merging", item 8, says why `gh pr checks` comes
first.

The raw rollup needs care. A GitHub Actions entry is a `CheckRun`. Its progress
field is `status`, with `QUEUED`, `IN_PROGRESS` and `COMPLETED`. Its `state` is
always null, because `state` belongs to `StatusContext`. Its `conclusion` is an
empty string until the run finishes. jq's `//` falls through on `null` and
`false` only. Read `conclusion`, then `status`, then `state`:

```bash
gh pr view <n> --json headRefOid,statusCheckRollup -q '
  def st: if (.conclusion // "") != "" then .conclusion
          elif (.status // "") != "" then .status
          elif (.state // "") != "" then .state
          else "PENDING" end;
  "\(.headRefOid) \([.statusCheckRollup[]|st]|join(","))"'
```

The two expressions on the same ten checks, five of them unfinished:

```
NEW: SUCCESS,IN_PROGRESS,IN_PROGRESS,SUCCESS,SUCCESS,IN_PROGRESS,IN_PROGRESS,SUCCESS,SUCCESS,IN_PROGRESS
OLD: SUCCESS,,,SUCCESS,SUCCESS,,,SUCCESS,SUCCESS,
```

Test any replacement against a pull request that has an unfinished check. Two
expressions agree once everything has completed, so a settled pull request
proves nothing. Build a fixture from a row that you copy from a real `gh` call,
with its `status` and its null `state`. A hand-composed row that looks right is
not evidence.
