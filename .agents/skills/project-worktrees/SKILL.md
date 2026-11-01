---
name: project-worktrees
description: "Use when creating, entering, debugging or removing a worktree under `.worktrees/`, when its URL returns 404 or 502, when writing worktree tooling, or for the Docker stack or a container process."
---

# Git Worktrees

## Overview

Every worktree is a full application of its own, with its own URL, database and compiled CSS. Only nginx and Mailpit are duplicated. php-fpm, Postgres and the Mercure hub are shared with the main stack. Mercure sits behind a compose profile and stays off until someone runs `just mercure-up`. A worktree that works on site-review push must start it, and then shares that one hub with every other worktree.

The main checkout is mounted at `/var/www/html`, and worktrees live inside it. So the files of a worktree sit at the same path in its own nginx sidecar and in the shared php-fpm. Only the document root differs. A worktree is served through its own `public/index.php`. Symfony therefore boots with the worktree as its project directory, and reads the `.env` chain of that worktree, over HTTP and on the CLI alike.

## The shared Docker stack

The project runs on Docker Compose, with commands in a `justfile` (requires [just](https://github.com/casey/just)).

1. `COMPOSE_PROJECT_NAME` is set in `.env` and is required. Compose refuses to start without it. It names the containers, the traefik routes and the database volume. When you bootstrap a new project from the skeleton, change it before the first `docker compose up`. Two projects that share a name share containers and the database volume.
2. Start the stack with `docker compose up -d` (nginx, php-fpm, postgres). Install PHP dependencies with `composer install`. Run migrations with `bin/console doctrine:migrations:migrate`.
3. The main app runs at `https://loupe.dev.localhost`, and PHP-FPM is on port 9000.
4. The `worker` compose service consumes the `scheduler_default`, `card_eval`, `jobs` and `card_sweep` transports. Use `docker compose logs worker` to observe it, or `just worker` for a foreground consumer.
5. Traefik TCP routing exposes Postgres at `db.loupe.dev.localhost:5432`. `.env` ships `127.0.0.1:5432` as a placeholder. On your host machine, set `DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"` in `.env.local`. Inside the php-fpm container (`just shell`), `compose.yaml` sets `DATABASE_URL` to `database:5432`, so nothing is needed.
6. The dev database runs without durability. The `database` service turns off `fsync`, `synchronous_commit` and `full_page_writes`, because dev and test data can be rebuilt. A Docker crash can corrupt it. `docs/contributing/development.md` says how to rebuild it.

## Quick reference

| Command | Does |
|---|---|
| `just worktree-up NAME [CONTEXT]` | Provision (or repair) a worktree, from anywhere. Idempotent. `CONTEXT` sets the site-review marker, see "The card marker". |
| `just worktrees` | Every worktree with its URL, database and sidecar status |
| `just worktree-down <name>` | Remove worktree + sidecar + both databases |
| `just worktree-prune` | Clean up sidecars/databases orphaned by `git worktree remove` |
| `just worktree-tailwind` | Tailwind watch mode for the current worktree. Rarely needed: the `tailwind-worktrees` service already rebuilds every worktree. |

Each worktree gets these names:

- the URL `https://<slug>.loupe.dev.localhost`
- the dev database `app_wt_<slug>`, and the test database `app_test_<slug>`
- the compose project `loupe-wt-<slug>`
- a Mailpit sidecar at `https://mailpit-<slug>.loupe.dev.localhost`, with the SMTP alias `mailpit-<slug>` on the app network

Bootstrap refuses a name that normalises to a slug that begins with `mailpit-`, because it would claim the mail host of a sibling.

Log in with `dev@loupe.test` / `password`, or `admin@loupe.test` / `password` for the admin area. To skip the login form, run `bin/console app:dev:preview-login-link --path=/projects` inside the worktree. It prints a signed link that signs you in and lands you on that page. The signature covers the host, so the link works against that worktree only. It does not expire.

Run `just up` first. Bootstrap fails fast, so that no worktree keeps an `.env.local` that points at a database nobody created.

## The compiled stylesheet keeps itself current

`worktree-bootstrap.sh` builds `var/tailwind/app.built.css` as it provisions, and the `tailwind-worktrees` service keeps it current afterwards. That service polls every worktree every few seconds. It rebuilds each worktree whose `assets/` or `templates/` are newer than its built sheet. One container covers them all, and a worktree created later needs no wiring.

The service does not conflict with the `tailwind` service or with `just tailwind`, which watch the main checkout. Each worktree has its own `var/tailwind`, so the two never write the same file. Two watchers on one tree still race.

Before you change the service, read `references/tooling-internals.md`. It holds the rules the watcher follows and the timings behind them.

## The lifecycle runs itself

The Claude Code compatibility adapter in `.claude/settings.json` registers two hooks. Its worktrees therefore arrive provisioned and leave nothing behind. Other harnesses can call the neutral scripts directly.

| Hook | Script | Receives | Does |
|---|---|---|---|
| `WorktreeCreate` | `.agents/hooks/worktree-create.sh` | `name` | Creates the worktree, runs `worktree-bootstrap.sh`, prints the path |
| `WorktreeRemove` | `.agents/hooks/worktree-remove.sh` | `worktree_path` | Runs `worktree-teardown.sh` with `WORKTREE_TEARDOWN_KEEP_TREE=1` |

The two events have different contracts. `references/tooling-internals.md` gives the full `WorktreeCreate` contract and its rollback rule.

`WorktreeRemove` cannot block, because the harness logs its exit code only in debug mode. That script is best-effort and idempotent. It leaves `git worktree remove` to the harness, so the two cannot race. `just worktree-down NAME` by hand removes the tree too. Both hooks ignore anything outside `.worktrees/`. The hook `timeout` is in seconds and defaults to 600, which these hooks use. Bootstrap must fit inside it.

Teardown kills anything that still runs against the worktree in the shared php-fpm before it drops the databases. It matches on the working directory of the process, because the console processes of all worktrees have identical command lines in one container. It drops with `--force`. A surviving consumer used to make `dropdb` fail without an error, and orphan both databases.

## Rules that prevent real damage

Prefer the `NAME` form of every worktree command, and run it from the main checkout: `just worktree-up NAME`, `just worktree-down NAME`. `worktree-up` also accepts no argument, and falls back to the tree you stand in. That fallback is why sessions used to `cd` into a worktree. A `cd` persists across later tool calls and moves the whole session (AGENTS.md, "The main session never moves into a worktree"). A run from main is also correct by construction, because the bare `docker compose` calls inside the bootstrap script resolve their compose file from the current directory.

One php-fpm container serves every worktree. `bin/worktrees/compose-exec.sh` execs into `loupe-php-fpm-1` and varies only `--workdir`. Worktrees are isolated per test database, never per container. The isolation comes from the `TEST_TOKEN` that `worktree-bootstrap.sh` writes into each `.env.test.local`. `tests/bootstrap.php` adds `__p<n>` for ParaTest worker `n`, so one worktree holds `app_test_<slug>__p1` to `__p4` beside `app_test_<slug>`.

A process started inside a container can only be observed and stopped from inside it. The container has its own PID namespace. A host-side `pkill -f <script>`, and the harness's own `TaskStop`, kill only the wrapper. They report success while the real process keeps running, and the host process table does not show it. Stop it with `docker compose exec php-fpm pkill -f <script>`, and confirm with `docker exec <project>-php-fpm-1 ps aux`. A host-side check shows a quiet container that is in fact fully loaded.

This has consequences:

- `docker compose exec php-fpm pkill -f phpunit` kills the run of every worktree.
- A pattern that names the worktree matches nothing, because `--workdir` never reaches the command line. Find the run by its working directory, then kill it by pid:

  ```bash
  docker exec loupe-php-fpm-1 sh -c \
    'for p in $(pgrep -f "^php vendor/bin/phpunit"); do echo "$p $(readlink /proc/$p/cwd)"; done'
  ```

  Keep the `^` anchor, or the pattern also matches the `sh -c` wrapper. BusyBox `ps` has no `-p`, so test liveness with `test -d /proc/<pid>`.
- Two gates in one worktree share a token and hit one database. They crash in `tests/bootstrap.php` with `duplicate key value violates unique constraint "pg_type_typname_nsp_index"`. Gates in different worktrees are safe.

Never run `npm ci` or `npm install` inside a worktree. Bootstrap links `node_modules` and `e2e/node_modules` to the main checkout, so an install writes through the link. `npm ci` deletes the shared directory first, which breaks `just cs` in every tree. The worktree then keeps a private copy that stops tracking main. Install from the main checkout. Card 123 tracks a real fix.

Before you trust any write, confirm that the path is in `git worktree list`. A path that exists on disk can mislead you. Suppose a worktree is removed while an agent is still bound to it. A write to the old path then reports success. It recreates a bare directory that is not a git worktree, so nothing lands on the branch and nothing raises. `git status` there falls through to the main checkout and reports `main`. Check membership:

```bash
git worktree list --porcelain | grep -qx "worktree $(pwd)" || echo "NOT a worktree — stop"
```

An agent in this state must stop and not improvise. A checkout of the branch into the main checkout collides with `main`, which is already checked out there. `cp`, `rsync` and `git checkout` from the path of another worktree bypass the write binding and do not repair it. Only the orchestrating session can fix it, by provisioning a worktree with that branch and rebinding.

Never run bare `docker compose up/down/restart` from a worktree. `.env` sets `COMPOSE_PROJECT_NAME=loupe`, so compose targets the shared stack but resolves `.` to the worktree. It then recreates the containers of the main app with the worktree as their document root. Use the `just` recipes. To run something in the shared php-fpm against the files of the current worktree, use `bin/worktrees/compose-exec.sh`.

A `docker compose -f <file>` call with unset variables does not fail safely either. `docker/compose/e2e.yaml` and `docker/compose/worktree.yaml` declare their inputs with `${VAR:?}`, which protects `up` but not `down`. A `down` on one of them without those variables removed the containers of the main stack. It also tried to delete the `loupe_default` network, although `-p` named a different project. A teardown recipe must pass the same variables as its bring-up.

Tear down with `just worktree-down`, never with a bare `git worktree remove`. A bare remove leaves both sidecars and both databases behind, and the route then serves 502s. `just worktree-prune` cleans up after the fact.

Recover the identity of a worktree from the compose project label, never from a container name. A worktree has more than one sidecar. Code that strips a service suffix (`-nginx-1`) breaks when a different service is the survivor. Prune once parsed a Mailpit-only orphan as the slug `<slug>-mailpit-1`. It then drove a teardown, and a database drop, from a slug that belonged to nothing. Read the label with `docker ps --format '{{.Label "com.docker.compose.project"}}'`. A new service must not break cleanup. Teardown also passes `--remove-orphans`, so a `down` still removes a running service that the resolved compose file does not declare.

The slug is not the directory name: `Feature_X` owns the slug `feature-x`. Any tooling that matches worktrees (pruning, listing, collision checks) must resolve them through `bin/worktrees/slug.sh` (`worktree_slug`, `worktree_db_token`, `worktree_slug_index`). Never string-match a directory name. A wrong match once declared a live worktree orphaned and dropped its databases.

Serena's edit tools do not work from a worktree. The MCP server is bound to the main checkout, so `replace_symbol_body` / `insert_*_symbol` / `replace_content` write there with no warning. Your branch stays unchanged, and the main tree gets dirty. Use Edit/Write. Serena read tools are safe from anywhere.

## Claude Code write binding

Other harnesses must bind the writes and the working directory of each worker to the same worktree. Then they must verify both before an edit. The details below document the Claude Code compatibility behaviour.

The Edit/Write binding is single-valued per agent, and depends on how the agent was launched.

Plain subagents inherit the current worktree of the parent session. They do not get the worktree they were launched against, or the one they `cd` into. An edit anywhere else is rejected with "This session is now isolated in `<path>`". A subagent that calls `EnterWorktree` moves its `pwd`, but does not rebind its write access. So when you dispatch N subagents at N worktrees, all but the bound one fail without an error. Serialize instead: `EnterWorktree(path)` in the parent, dispatch, wait, rebind, next.

Check the confirmation wording before you dispatch. "The session is now working in the worktree" propagates to subagents. "This agent's working directory and write access now point at the worktree" applies to the parent only. It appears when you switch straight into a freshly created worktree. Re-issue `EnterWorktree` until you get the first wording.

Agents launched with `isolation: "worktree"` get their own binding and can write in parallel. A harness that follows the neutral convention places the tree in `.worktrees/agent-<id>`. The `WorktreeCreate` hook provisions it, so it is a full application from the start, and not a bare `git worktree add`. If the hook is not registered, run `just worktree-up` yourself. The harness generates the name (`agent-<id>`, and the branch takes that name). Work that must land on a named branch needs a deliberate rename and push. These worktrees are created locked, but `just worktree-down agent-<id>` still removes one cleanly, sidecars and both databases included, with no `--force`.

Tear the worktree of an agent down only after its branch is pushed. Never tear down the worktree that the parent session is bound to. The binding keeps pointing at the deleted path, and every later Edit fails until you `cd` elsewhere and re-issue `EnterWorktree`.

Verify the binding before you dispatch, not after. Make one trivial edit in the target worktree and confirm that it lands. Three times in one wave, an agent found only mid-task that its writes were pinned elsewhere, and each time cost a full agent run.

Give every subagent the same step-0 writability check. Tell it explicitly to stop and report if the check is rejected. Forbid the plausible workarounds, and not only the obvious ones. An agent told not to use `cat`/`sed` edited the wrong worktree and `cp`'d the finished file across. It reported that honestly, but it bypassed the guard, and the next agent may not verify as carefully. `cp`, `rsync`, `git checkout` of the path of another worktree, Serena's edit tools and any shell redirection are all bypasses. If writes land in the wrong worktree, stop, and let the orchestrator fix the binding. Work already on disk survives a stopped agent, so resume it with a message and do not restart it.

Parallel writes do not yet parallelise the gate. Each worktree has its own Mailpit sidecar, and one suite runs on 4 workers, but every worktree shares the one `php-fpm` container. Nobody has gated two branches at the same time, so do not assume that it works.

## Reusing one worktree for several branches

Only one worktree is writable at a time, so a wave of branches often runs through a single provisioned worktree. Its directory name then stops matching its branch. That is expected, so do not "fix" it.

Run these steps after every `git checkout <other-branch>` in a reused worktree, before any gate can mean anything:

1. Run `bin/worktrees/compose-exec.sh composer install`. The lockfile differs between branches. A drifted `vendor/` produces errors that look like application bugs. It once reported `Call to undefined method` for a method that the locked package version does define.
2. Run `bin/console cache:clear` for both `dev` and `--env=test`. If you clear only one, phpstan has no dumped dev container ("Container ... does not exist"). A `var/cache` that survived a Symfony minor upgrade gave a 500 on every page that rendered an icon, and 31 e2e specs failed from that one stale cache.
3. Run `bin/console doctrine:migrations:status`. A migration from the previous branch shows as executed but unavailable. If it does, reset the dev database (drop, create, migrate), then run `just worktree-up` to seed it again. Otherwise e2e runs against the schema of another branch.

When a gate fails right after a branch switch, suspect this list before you suspect the branch.

## Symptoms and causes

| Symptom | Cause and fix |
|---|---|
| Worktree URL returns **404** (plain text, no app markup) | The sidecar container is gone, so Traefik has no router for that host. `just worktree-up` recreates it without touching the database. |
| Every fix run of a card fails in the bridge `before` step with `'<branch>' is already used by worktree at '<path>'` | A session worktree still holds the pull request branch. `bridge-before.sh` detaches that worktree when it has nothing uncommitted or unpushed, also when `card-<n>` already exists. Otherwise it stops and names the worktree. Commit and push its work there, then resume the card. Do not run `just worktree-down` before that push, because it force-removes the worktree and deletes uncommitted files. A session that links a pull request to a card hands the branch to the bridge, so tear its worktree down after the push. |
| Worktree URL returns **502** | The route exists but the backend does not. The usual cause is a worktree removed with a bare `git worktree remove`. Run `just worktree-prune`. |
| nginx exits with `host not found in upstream "php-fpm"` | The container reached the shared network after start. Attach every network at creation. That is why the sidecar is a compose file, and not `docker run` + `docker network connect`. |
| A class added in the worktree renders unstyled | `var/tailwind` must be a real directory per worktree, and not a symlink to the one of main. `just worktree-up` fixes it, and `just worktree-tailwind` watches. |
| Layout looks like an older design, or a hover/position spec fails but the page logs nothing | The `tailwind-worktrees` service is down. It keeps a worktree from serving its provision-time CSS while its Twig and PHP are current. Check `docker compose logs tailwind-worktrees`, and run `docker compose up -d tailwind-worktrees` if it is not running. A one-off rebuild is `bin/worktrees/compose-exec.sh bin/console tailwind:build`, under a second. Before you blame the branch, diff the compiled sheet for a class that the design introduced. |
| The site-review widget shows a red `!` on the launcher, and "This review widget can't connect" | The embed names a project that does not resolve at the backend the widget talks to. That backend is `SITE_REVIEW_WIDGET_BACKEND`, not the host that serves the page. A worktree keeps the project id of the main checkout, which production knows. Suspect an `.env.local` with no `SITE_REVIEW_WIDGET_PROJECT`. Also suspect a production project that does not list `https://<slug>.loupe.dev.localhost` under its allowed sites, or a widget branch pointed at its own tree with the production project id. See "Which backend the widget talks to". |
| Widget comments on a card preview save, but the card does not list them | The worktree has no `SITE_REVIEW_WIDGET_CONTEXT` in its `.env.local`, so the comment carries no marker. Run `just worktree-up card-<number> card:<cardId>` again from the main checkout, with the production card id. See "The card marker". |
| The widget says "This preview's card is closed or gone, so notes cannot be saved here." | The marker names a card that the widget backend does not hold. Suspect a widget branch whose `SITE_REVIEW_WIDGET_BACKEND` is its own worktree host, with a production card id. Remove `SITE_REVIEW_WIDGET_CONTEXT` from its `.env.local`, or set `card:<id>` with a card id from the database of that worktree. See "The card marker". |
| The widget does not appear at all, with no launcher and no error badge | The `<script>` failed to load, so nothing ran. A refused sign-in still renders the widget, and an unreachable script renders nothing. Check that the backend host resolves and serves `/live-review/widget.js`. A `SERVFAIL` on `app.loupe.ac` from the resolver of the machine produces exactly this, while the same request succeeds through `1.1.1.1`. |
| Mail-asserting specs never see their message, or read the message of another run | Each worktree has its own sidecar, so a run must point at it. Suspect a run launched without `just e2e` (which exports `MAILPIT_URL`), or with `E2E_BASE_URL` set alone, which falls back to the shared instance. The app of the worktree then sends where nothing reads. Every registration, login and verification spec times out, and it looks like an auth regression. Set `MAILPIT_URL=https://mailpit-<slug>.<project>.dev.localhost` alongside it. `bin/e2e-target.sh` prints the Mailpit URL it resolved as its fourth line. |
| A spec fails, then passes on a quiet re-run | Something else loaded the shared php-fpm, such as a sibling agent that ran `just ci` or `composer install`. Check what is in flight before you investigate the branch. This once nearly filed a false "regression" against a clean PR. |
| `worktree-up` fails with an "Unrecognized option" or missing-class error | The new worktree seeded its `vendor/` from a stale main checkout. A fast-forward of the main checkout does not update its `vendor/`, so every worktree created afterwards inherits the dependencies of the old commit. Run `composer install` in the main checkout, then run `just worktree-up` again. |
| Every e2e spec fails on a new worktree host with `ERR_CERT_AUTHORITY_INVALID`, while existing hosts stay fine | The `traefik-dnsmasq-1` container (in the separate `traefik` stack) is down. It holds `address=/dev.localhost/172.20.0.2`, the wildcard that lets step-ca resolve a host to validate ACME. Without it, `tls-alpn-01` fails with "could not connect to validation target", and Traefik serves its default cert. Hosts already in `certs/acme.json` keep working, so the failure looks worktree-specific. `restart: unless-stopped` does not revive it after an exit 255. Fix it with `( cd ../traefik && docker compose up -d dnsmasq )`. Then restart Traefik too, because it otherwise keeps polling the failed authorization and opens no fresh order. Verify with `curl -o /dev/null -w '%{ssl_verify_result}'` (0 = good) before you blame the branch. |
| Mail-asserting e2e specs time out against a worktree, or Mailpit has no mail | A missing consumer is not the cause: `PlaywrightSyncMiddleware` handles `X-Playwright` dispatches inline. First check that the Mailpit sidecar of the worktree is up (`just worktree-up` recreates it). Then check that `MAILER_DSN` in the container resolves to `mailpit-<slug>`. Container environment beats dotenv, so a php-fpm that still carries an old `MAILER_DSN` sends to the shared instance until the main stack is recreated. Otherwise, suspect a request that never carried the header (a context built without the `use` options of the project), or stale state from an interrupted run. |
| You cannot tell whether an e2e run is already in flight | `ps ax` truncates command lines to terminal width. The `e2e/node_modules` of a worktree is a symlink to the main checkout, so every playwright process reports an identical command line, whichever tree launched it. Use `pgrep -f 'playwright test'`. It does not truncate, but it cannot tell trees apart either. Treat any hit as "someone is running e2e", and wait. |

## Which backend the widget talks to

The widget's embed is built from two independent values. When they get out of step, the widget of a worktree breaks. That is the most common way it breaks.

```twig
<script src="{{ site_review_widget_backend }}/live-review/widget.js"
        data-project="{{ site_review_widget_project }}"></script>
```

`widget.js` then does `BACKEND = new URL(script.src).origin`. The widget posts to the origin of its script, never to the host that serves the page. So a page on `https://<slug>.loupe.dev.localhost` whose script comes from `https://app.loupe.ac` sends its comments to production. That is deliberate, because it is how the team dogfoods the app. You annotate the local UI, and the comments land in hosted Loupe, where an agent reads them over MCP. It also means that the project must live on the backend, and not in the local database.

### The rule for a worktree

A worktree points at production, with the production project, so annotating a branch works the way it does everywhere else:

```
SITE_REVIEW_WIDGET_BACKEND=https://app.loupe.ac
SITE_REVIEW_WIDGET_PROJECT=<the production project id, same one the main checkout uses>
```

The embed carries no credential. The reviewer presses **Sign in with Loupe**, and a pop-up opens on the backend. That project must list the origin of the page under its allowed sites, or the consent page refuses the sign-in. One wildcard entry covers every worktree at once: `https://*.loupe.dev.localhost`. Only the owner of the project can sign in.

The exception is a branch that changes the widget itself: anything under `public/live-review/` or `src/Module/SiteReview/` that alters widget behaviour. Point that branch at its own tree, so that the code under review is the code you exercise:

```
SITE_REVIEW_WIDGET_BACKEND=https://<slug>.loupe.dev.localhost
SITE_REVIEW_WIDGET_PROJECT=<the worktree's own project id, see below>
```

Judge that by what the diff touches, and not by the branch name. A change to `ResolveSiteReviewCommentHandler` is not a widget change. A change to `widget.js` is.

### Bootstrap keeps the pair in step

`worktree-bootstrap.sh` copies `.env.local` from the main checkout, and leaves both `SITE_REVIEW_WIDGET_BACKEND` and `SITE_REVIEW_WIDGET_PROJECT` as it found them. A worktree therefore talks to production with the project that production knows, and the site-review comments of a worktree page land in the real project. You annotate the branch you work on, and read the comments back over MCP like any other site.

Bootstrap captures no local project id. It runs `app:dev:seed`, which seeds the full demo dataset in the own database of the worktree, switches on the flags `inbox.enabled`, `artifacts.enabled` and `billing.enabled` with the tag `dev-seed`, and prints the id of "Dev Project". Bootstrap sends that output to `/dev/null`. `--keep-flag-defaults` skips the flag rows and the billing data, and only the e2e stack passes it.

### The card marker

`just worktree-up NAME CONTEXT` writes `SITE_REVIEW_WIDGET_CONTEXT=CONTEXT` into the `.env.local` of the worktree. The embed carries it as `data-context`, and every widget comment stores it. For a card worktree, `CONTEXT` is `card:<cardId>`. `LinkCardOnSiteReviewCommentCreated` on production then links the comment to that card.

The marker must name a card in the database of the widget backend, `SITE_REVIEW_WIDGET_BACKEND`. Most card worktrees point the backend at production, so `<cardId>` is the production card id. A card id from the own database of the worktree names no card there.

A branch that changes the widget itself points the backend at its own worktree host (see "The rule for a worktree"). A production card id then names no card on that backend, and the widget says "This preview's card is closed or gone, so notes cannot be saved here." For such a branch, pass no marker, or pass `card:<id>` with a card id from the own database of the worktree.

The marker is a formal argument all the way:

- The bridge `before` rule passes `{cardId}` to `bin/worktrees/bridge-before.sh`, which passes it to bootstrap.
- The script passes no marker when the `SITE_REVIEW_WIDGET_BACKEND` of the worktree differs from that of the main checkout, so a widget branch keeps the marker it set.
- On a refresh, the stage skill reads it from the prompt line `Card <number> (cardId <id>)`. When the prompt lacks it, take `cardId` from `card_get`.
- Never derive the marker from a branch name, a worktree name or a card number, and do not add code that does.

Bootstrap writes the marker only when `CONTEXT` is present. A re-run without it keeps the old value, so a repair never clears a marker. Pass it again on a refresh all the same, because that also fixes a worktree created without it.

A widget branch needs both values changed by hand after provisioning, because bootstrap restores neither. Point the backend at the worktree host. Then run the seed again, and read the project id it prints:

```bash
( cd .worktrees/<name> \
  && bin/worktrees/compose-exec.sh bin/console app:dev:seed \
  | grep SITE_REVIEW_WIDGET_PROJECT )
```

The seed is idempotent, so it prints the id of the project it already made. Then add `https://<slug>.loupe.dev.localhost` to the allowed sites of that project, on the own Connect page of the worktree. Sign in as `dev@loupe.test`, which owns the seeded project. The consent page refuses every other account.

To diagnose a refused sign-in, read the embed that the page serves:

```bash
curl -sk https://<slug>.loupe.dev.localhost/login \
  | grep -A1 'live-review/widget\.js'
```

That needs `SITE_REVIEW_WIDGET_PUBLIC` set. Otherwise `templates/_site_review_widget.html.twig` serves the tag to an administrator alone, so read the page in a signed-in browser instead.

Read the two lines together. The origin in `src` says which instance answers, and `data-project` must name a project that instance holds. A local id against production, and a production id against the worktree, both end at "This review widget can't connect".

### The preview token

After the seed, `worktree-bootstrap.sh` runs `loupe preview-token mint --project <SITE_REVIEW_WIDGET_PROJECT> --label <slug> --instance <SITE_REVIEW_WIDGET_BACKEND>`, with `--card` from the card marker. It writes the result to `SITE_REVIEW_WIDGET_PREVIEW_TOKEN` in `.env.local`. `worktree-teardown.sh` revokes it. A failed mint prints one warning and writes an empty value, so `worktree-up` never fails for it. The warning means that the CLI is missing, signed out, or signed in to an instance other than the widget backend. A branch whose backend is its own worktree host mints from its own database with `app:dev:preview-token`, with no card.

## Writing worktree tooling

Read `references/tooling-internals.md` before you write or change a script under `bin/worktrees/`. It covers helper sourcing, `{{ }}` escaping in `just`, and relative symlink depth.

## Testing

Each worktree gets its own test database through `TEST_TOKEN`, so `just ci` runs in parallel across worktrees safely.

For the full e2e suite, reach for `just e2e-up` first, and not a worktree. It provisions a disposable `app_e2e` database and a sidecar that serves the main checkout. So it gates whatever branch is checked out there, with no tree, no cert and no seeded database. You can still point e2e at a worktree. That is the right tool when you must gate a branch without checking it out, but it costs more.

Run `just e2e` from a worktree, and it resolves that worktree as its target and its Mailpit, and prints both. It also rebuilds the stylesheet of the worktree, and repairs the dev data that the suite destroys. Every instruction that says "from the worktree" needs the worktree as its cwd for that call only, so wrap the `cd` in a subshell:

```sh
( cd .worktrees/<name> && just e2e )
```

A bare `cd` persists across later tool calls. It turns "run this one command there" into "move the session in", which AGENTS.md forbids for the main session.

`E2E_BASE_URL` still overrides, and aims a run at a worktree from somewhere else. It also suppresses the repair and the per-worktree Mailpit, because an explicit target can be anything. A repair of a tree that nobody chose is worse than no repair, and a guessed mail host is worse than the shared one. Set `MAILPIT_URL` alongside it to aim the mail assertions too.

## Running the full e2e suite from a worktree

The worktree e2e gate needs these conditions:

1. `DEFAULT_URI=https://<slug>.loupe.dev.localhost` in the `.env.local` of the worktree. Otherwise, CLI-generated absolute URLs (export download emails) point at `localhost`. Check it again after any re-provisioning, because `worktree-up` regenerates `.env.local`.
2. No worker, and no worktree-scoped `messenger:consume`. The shared `worker` container consumes the database of main only. `PlaywrightSyncMiddleware` handles every message dispatched under `X-Playwright` inline, so there is no queue to drain. The middleware is registered `when@dev` only, which is what a worktree runs.
3. A quiet stack. During the run, allow no worktree provisioning, no `composer install`, no sibling `just ci`, and no e2e suite of a sibling worktree. A worktree gets its own nginx and Mailpit and nothing else. `php-fpm`, `mercure` and `database` are shared, so any of these skew timings past the Playwright timeouts. Per-worktree databases and mail sidecars make two suites correct together, but not reliable together. The live-update and debounced specs fall over (comment counters, the sliding-confirm overlay, search), and their failures look like real regressions. Run the suite again on a quiet stack before you accept any such failure.
4. A current stylesheet. `just e2e` runs `tailwind:build` for the detected worktree before it starts Playwright, so this is automatic. A run started any other way still gates against the CSS of provision time. That includes `npx playwright test` directly, or an explicit `E2E_BASE_URL`, which suppresses worktree detection.

Never run the full suite against the live dev database of the main checkout once it holds real data. State-dependent specs fail. Worse, they change live state: a waitlist spec run against main set `registration.cap` and closed registration with no warning.
