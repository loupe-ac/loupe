# Aiming a local run at a sibling worktree

Read this file before you set `E2E_BASE_URL` by hand to run a spec against
another worktree. The run wipes the dev data of that worktree, and nothing
repairs it for you.

Name the spec here too. Set both variables:

```bash
E2E_BASE_URL=https://<slug>.loupe.dev.localhost \
MAILPIT_URL=https://mailpit-<slug>.loupe.dev.localhost \
just e2e tests/<area>/<spec>.spec.ts --workers=1
```

`E2E_BASE_URL` alone suppresses worktree detection. The run then reads the
*shared* Mailpit, while the app of the worktree sends to its own sidecar.
Nothing collides and nothing warns, and the assertions never see a message.
About two dozen registration, login and verification specs then fail. The
failure reads as broken auth, not broken mail. Each registration *succeeds*,
times out while it waits for mail that went elsewhere, and its retry fails as a
duplicate email. Diagnose it with `bin/e2e-target.sh`, with and without the
variable, and diff the fourth line. Do not diagnose it by a re-run.

Warm its cache first:

```bash
( cd .worktrees/<name> && bin/worktrees/compose-exec.sh bin/console cache:warmup )
```

This destroys the dev data of that worktree. The `install-reset` project of the
suite truncates every table. `just e2e` normally repairs the worktree
afterwards. `bin/e2e-target.sh` deliberately blanks the worktree name when you
set `E2E_BASE_URL` by hand, so the automatic repair does not run for exactly
this invocation. Repair it yourself:

```bash
bin/worktrees/worktree-bootstrap.sh <worktree-name>   # from the main checkout
```

Use bootstrap, not a bare `app:dev:seed`. Bootstrap re-seeds, and it also
re-runs the migrations, restores the per-worktree `.env.local` values and
rebuilds the stylesheet.

Two runs that omit `MAILPIT_URL` cannot overlap. Each worktree has its own
Mailpit sidecar. Runs started with plain `just e2e` in different worktrees are
therefore isolated, and so are runs that set both variables above. Two runs that
set only `E2E_BASE_URL` both fall back to the *shared* instance and read the
mail of each other. Serialise those, or give each its own `MAILPIT_URL`.
