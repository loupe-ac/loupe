# Worktree tooling internals

Read this file before you change `worktree-bootstrap.sh`, the `tailwind-worktrees` service, the lifecycle hooks, or any script under `bin/worktrees/`.

## The `tailwind-worktrees` watcher

The watcher follows these rules. Each of them matters when you change it.

It rebuilds only a sheet that already exists. A missing sheet belongs to bootstrap. That split stops the two from writing the same file at once.

It globs `.worktrees` and does not walk it, so a worktree name can carry up to two path segments. That limit is deliberate. A worktree holds a real `vendor/` of about 28,000 files. `-path` filters what `find` prints, not what it walks. So an exhaustive scan cost 13.8 seconds a pass, and a pruned one 1.7. The globs cost 0.3. The shared php-fpm serves every worktree, and background load there skews e2e timings enough to cause failures that read as real. A cheap watcher matters more than a name nobody uses.

It scans the whole worktree, and not only `assets/` and `templates/`. The automatic detection of Tailwind covers the checkout, which is why `app.css` excludes `.claude` and `docs` explicitly. A class can sit anywhere: `config/packages/ubermuda_admin.yaml` carries `!bg-sunken`. A scan of the root also picks up `composer.lock`, so a `composer install` on a branch switch also rebuilds. That matters because `app.css` imports the CSS of a vendored bundle.

Two of its prunes are load-bearing, and not an optimisation:

- `var` holds the log and the cache, which change on every request. Without the prune, the watcher rebuilds for ever.
- `.claude` is where worktrees live. Without the prune, a worktree scans its siblings.

A whole pass costs 0.42 seconds against a ten-second interval. The interval is ten seconds because the service is the safety net for a sheet nobody rebuilt. `just worktree-tailwind` is the real watcher when you iterate on CSS.

`git worktree list` is the authoritative source, and the watcher cannot use it. It reports the host paths that created each worktree, and the watcher runs in a container. It fails by returning an empty list, not an error.

It stamps the built file after a successful build. Tailwind leaves the file alone when the output is unchanged. Without the stamp, the timestamp stays behind the source that triggered the build, and every pass rebuilds for ever.

## The `WorktreeCreate` contract

`WorktreeCreate` does not react to a worktree that the harness made. It receives a name, produces the worktree itself, and prints its absolute path as the last line of stdout. A non-zero exit or a missing path fails the creation, so all other output goes to stderr. The hook puts the tree at `.worktrees/<name>` on a branch of the same name, cut from `main` and not from the current branch.

If bootstrap fails, the hook removes what it created before it exits non-zero. The hook exists to remove a worktree that cannot run `just ci`, and an orphaned directory plus branch is a second problem.

"What it created" is exact, and an error here loses commits. When the hook provisions an existing branch, it creates only the worktree. So the rollback tracks the two facts separately. It deletes the branch only when it made the branch, and the ref still points where it put it. One flag for both once deleted an existing unmerged branch and its commits. Apply the same test to anything you add: does it undo something that existed before the run?

## Writing worktree tooling

- Source helpers relative to the script, never relative to `$main`. A script in a worktree that reads `$main/bin/worktrees/slug.sh` breaks whenever the helper is new on the branch. Use `. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/slug.sh"`.
- `just` claims `{{ }}`. To pass a Docker `--format` string through a recipe, write `'{{{{.Status}}'`. `{{{{` emits a literal `{{`, and `}}` needs no escaping.
- Use relative symlinks, and count the depth. A worktree sits two levels below main (`.worktrees/<name>`), plus one level per extra segment in a nested name. A legacy `.claude/worktrees/<name>` tree sits three levels below main. Links must be relative, so they resolve both on the host and inside the container, where the repo lives at a different absolute path.
