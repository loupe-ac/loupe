# Prove a conflict resolution lost nothing

Read this file when you resolve a conflict in a shared prose file, or in a
stacked branch after its parent squash-merged. The absence of conflict markers
proves nothing. These checks prove that no line and no test disappeared.

## Prove a resolution lost nothing

A union resolve on a shared prose file can keep your line and drop the line of
someone else. Nothing in this repository reports that. Use this check for any
file where branches append at a shared anchor:

```bash
git show origin/main:<file> | grep "^<entry prefix>" | sort > a
grep "^<entry prefix>" <resolved>                    | sort > b
comm -23 a b   # on main, missing from the resolution: must be empty
comm -13 a b   # invented by the resolution: this branch's own lines alone
```

A count of occurrences per entry is weaker. It needs a hypothesis about what to
expect, so it catches only a loss that somebody thought to look for. `comm`
needs no hypothesis and answers both directions at once.

## A stacked branch conflicts after its parent squashes

A branch stacked on a pull request that merged by squash conflicts in files that
it is a strict superset of. The squash gives `main` the same content under a new
commit that shares no history with the branch. Expect this at every stack merge.
Retarget the child to `main` first, as `SKILL.md` "Open a stacked pull request
against its parent's branch" says.

Apply the section above to a different unit. That section compares entries at a
shared anchor. This check compares test method names in `$F`, the test file that
both sides edited:

```bash
comm -23 <(git show origin/main:$F | grep -oE "public function test_[a-z_]+" | sort) \
         <(grep -oE "public function test_[a-z_]+" $F | sort)   # lost from main: must be empty
comm -13 <(git show HEAD:$F     | grep -oE "public function test_[a-z_]+" | sort) \
         <(grep -oE "public function test_[a-z_]+" $F | sort)   # lost from the branch: must be empty
```

Both directions matter. `comm` proves that no test disappeared. It does not
prove that any test still asserts what it did. It compares names, so a
resolution that keeps every name and guts a body passes it. This check
complements a read of the diff, and it never replaces it.
