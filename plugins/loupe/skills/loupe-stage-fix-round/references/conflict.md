# Resolve a conflict with the base

Read this file when the mergeability of the pull request reads `conflicting`, or the reason of the request is `conflict`.

`<base>` is the base branch of the pull request, from the forge adapter. For a stacked pull request, it is the parent's branch. Run these in the worker folder:

```bash
git fetch origin
git merge origin/<base>
git diff --name-only --diff-filter=U
git log --oneline $(git merge-base HEAD origin/<base>)..origin/<base> -- <conflicting files>
```

The last command names the commits on the base that caused the conflict. Read them and the pull request body before you resolve. Keep the intent of both sides. This procedure replaces the rule of the gate that resolves only a mechanical conflict. The gate's own `git merge origin/<base>` then has nothing to merge.

Never rebase. A rebase drops the merge commits that the branch already holds, and asks for each earlier resolution again.

When the two sides change one behaviour in ways that cannot both hold, run `git merge --abort`. Then stop with `STAGE RESULT: blocked: merge conflict with <base> in <files>`.

Prove each resolved file `$F` in both directions:

```bash
comm -23 <(git show origin/<base>:$F | grep "^#" | sort) <(grep "^#" $F | sort)   # must be empty
comm -13 <(git show origin/<base>:$F | grep "^#" | sort) <(grep "^#" $F | sort)   # the branch's own additions
```

The first command must print nothing, because each line it prints is work of the base that the resolution dropped. The second must list only this branch's own additions. Choose the grep to suit the file: headings for prose, test method names for a test file, entry prefixes for a list. Then check the structure of the file. A union of both sides can leave two bodies and one closing brace, so absent markers prove nothing.

Keep the `# Conflicts:` block in the commit message, and add one plain line per file that says how it was resolved. `git commit -m` drops the block, and the person who merges reads it to verify the resolution. Commit like this:

```bash
m=$(git rev-parse --git-path merge-desc)
{ cat "$(git rev-parse --git-path MERGE_MSG)"; echo; echo "<file>: <how it was resolved>"; } > "$m"
git commit -F "$m" --cleanup=verbatim
```

Put the resolution and nothing else in the merge commit. A fix for a check or a review goes in a later commit of its own.

The merge brings commits of the base, and the gate's own merge then brings none. So refresh the environment now, as "Refresh the environment" in `../../loupe-stage-implementation/references/commands.md` says. When "Sync with the pull request branch" sends you here, skip this refresh, because its item 7 refreshes once.

Then run the gate, and push as "Push without force" in `../../loupe-stage-implementation/references/commands.md` says. Never merge or approve the pull request.
