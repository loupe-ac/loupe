# Merging by hand: the rest of the protocol

Read this file before you hand-merge a pull request that no workflow rule
reaches, or while you hold the merge queue. `SKILL.md` "Merging" keeps the
protocol in short form. This file keeps the item numbers, so "merge protocol
item 5" means the same rule in both files.

## Item 5: a conflict-free merge is not a correct merge

When one branch renames a class, a *new* file on another branch merges cleanly
while it still imports the old name. Git sees no conflict, because the file
never existed on both sides. Push the merged result and read CI before you
trust such a merge. phpstan catches this, and git does not.

The signature variant is the same blind spot with a different tell. A branch
that makes an argument required lives on and absorbs merges. Each incoming
merge can bring a *new* file from a sibling that constructs the old shape. The
file existed on only one side, so git has nothing to report, and the result is
a runtime `ArgumentCountError`. A grep for the changed symbol is a good first
pass, and it is not sufficient. It finds only the shapes you thought to search
for, and it goes stale at the next merge.

A formatter that reads a signature belongs to the same family. `just cs` moved
a `number:` argument to the last position on one branch. The `Card` class of
that branch had no `$number` property, because the parent branch added it. The
rule is Rector's built-in `SortCallLikeNamedArgsRector`. It comes through
`GamacheSetList::CONVENTIONS` and is declared in
`vendor/ubermuda/gamache/src/Rector/config/conventions.php`, so a grep of
`rector.php` finds nothing. It sorts by `$order[$name] ?? \PHP_INT_MAX`, so a
name that the constructor does not carry sorts last.

An unchanged file does not prove that the ordering is correct. The rule bails
out when it cannot resolve the class, and a silent no-op reads exactly like a
pass. A green `cs-check` dry-run does not prove it either. On one branch the
dry-run flagged nothing, and `just cs` then reordered that file with the same
rule set in both legs.

Verify on a detached HEAD that merges the branch onto its parent. Run the tests
there, then return with the branch ref unmoved. That tests the change in its
destination, not in the tree it was written in. Read the version of one file on
the parent with `git show origin/<parent>:<path>`, which has no effect on the
cut point.

## Item 7: "Pull Request is not mergeable" right after a push

"Pull Request is not mergeable" right after `git push` usually means GitHub has
not recomputed mergeability yet. Wait a few seconds and retry.

## Item 8: read the checks against the head you merge

After you sync a branch, the rollup can still describe the *previous* head. A
green reading is then evidence about a commit that no longer matters. Key the
wait on `headRefOid`, then count the checks:

```bash
gh pr view <n> --json headRefOid -q .headRefOid
gh pr checks <n> --required --json bucket \
  -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

Count `pass`, `fail` and `pending` against the number of checks the ruleset
requires. A green reading is `pass=<that number>` and nothing else. Observe
all-green, and never infer it from an absence. The rule covers the polling
monitor and the one-shot check before a merge. A monitor that reports early
costs you a wait. A pre-merge check that reports a false green is acted on at
once, and the merge happens.

Lead with `gh pr checks`. Its `--required` flag reads the ruleset, so your
denominator cannot go stale the way a written list does. It prints an explicit
`pending` bucket, so it avoids the field problem of the raw rollup. Its
`--json` mode exits 0 while checks are pending, so read the counts and not the
exit code. `holding-a-merge-queue/scripts/merge-ready.sh` runs this count for
the queue holder.

`gh pr view --json statusCheckRollup` gives you the raw rollup, and it needs
care. `reading-the-rollup.md` holds the jq expression that reads it, and the
test for any replacement.

A rollup with fewer entries than the ruleset requires is the same hole one
level up. A run that has not registered reads the same as "nothing left to wait
for". Counting closes both.
