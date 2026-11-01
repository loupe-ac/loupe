---
title: "Development"
description: "The commands you will actually use, and the gate a change has to pass."
---

Everything runs in Docker; `just --list` shows every recipe.

```sh
just up                # start nginx, php-fpm, postgres
just composer install  # composer inside the container — never on the host
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # dev@loupe.test or admin@loupe.test / password
```

`just up` publishes no host port and needs a reverse proxy — see
[Reverse proxy](../extending/reverse-proxy.md) before the first command.

## The dev seed

`app:dev:seed` fills a development database with one demo dataset. Each module
owns its seeders in `Service/Dev/`, and the command runs them in dependency
order. The dataset holds the dev and admin accounts, two demo accounts, "Dev
Project", and the data that the Metrics, Dashboard, Experiments, Artifact and
analysis pages show.

- A run is safe to repeat. A seeder skips a record that exists, and a later day
  only adds the missing days of the time series. The seed never deletes a record
  that has a fixed id. It looks up each record with a fixed id on its own, and
  restores only the records that a person deleted. A restored card gets a new
  number, and the seed writes that number to the runs of the card. A restored
  run links its stored usage row again, so the cost of a card stays the same.
- A record that a page address names has a fixed id, so a preview link opens
  the same record on every database. The dev and admin accounts keep the id they
  have, because an existing database points projects at them.
- An old "Dev Project" is renamed to `Dev Project (old <8 characters of its id>)`.
  The new "Dev Project" gets the fixed id.
- The seed switches on the flags of `DevFeatureFlagSeeder`, which are
  `inbox.enabled`, `artifacts.enabled` and `billing.enabled`. It tags each flag
  row `dev-seed`.
  A flag row with that tag stays as a person left it, so the seed never switches
  it on again.
- `--keep-flag-defaults` writes no flag row and leaves the existing flag values
  unchanged. It also skips the billing data. The e2e stack uses it, because the
  specs assume the coded flag defaults.
- The command prints the time of each seeder. The target for a full seed is 30
  seconds on a worktree. No test checks it, so a reviewer must read the printed
  times when a change adds a seeder.

On 2026-11-01 a full seed of an empty, migrated database took 3.7 seconds by the
wall clock, including the start of the command. The seeders took 3.0 seconds
together. The run was on an Apple M1 Max with Docker, while other worktrees used
the same stack. The time on other hardware is unknown.

A feature that shows data on a page adds or extends a `DevSeederInterface`
implementation in its module. When the page sits behind a flag that defaults to
off, it also adds the flag to `DevFeatureFlagSeeder`.

## Day to day

```sh
just shell     # bash inside the php-fpm container
just composer  # e.g. just composer require foo/bar
just worker    # foreground messenger consumer, and the scheduler with it
just tailwind  # CSS watch mode (already running in dev)
```

Tailwind rebuilds automatically in the dev container. Do not run
`tailwind:build` by hand after editing templates — wait a second and re-check.
The same goes for `cache:clear`.

AssetMapper can retain old CSS when its cache and the watcher output change within the same second.
Its freshness check compares whole-second modification times.
Compare the served stylesheet with `var/tailwind/app.built.css` inside the container before attributing a visual failure to source code.
If the generated CSS is current but the served CSS is stale, update only the generated file's timestamp:

```sh
just exec touch var/tailwind/app.built.css
```

Confirm that a fresh page response uses a new stylesheet hash before rerunning the browser check.

The `tailwind` service watches the main checkout only. Empty mounts hide
`.worktrees/` and `.claude/worktrees/` from it, and the `tailwind-worktrees`
service rebuilds the worktree sheets. After a change to `compose.yaml`, run
`docker compose up -d tailwind` from the main checkout to recreate the watcher.

## The dev database

The `database` service turns off `fsync`, `synchronous_commit` and
`full_page_writes`, because dev and test data can be rebuilt. A Docker crash can
corrupt the database. Recreate the service with `docker compose up -d database`
from the main checkout, and only while no bridge worker runs, because it drops
every connection.

The `database_data` volume holds the dev database and every worktree database.
To rebuild a corrupt volume:

1. Stop the stack with `docker compose down`.
2. Remove the volume with `docker volume rm <COMPOSE_PROJECT_NAME>_database_data`.
3. Start the stack with `just up`.
4. Run `just migrate-run` and `just exec bin/console app:dev:seed`.
5. Run `just worktree-up <name>` for each worktree.

Run one unit test with `just phpunit --filter TestClassName`, one JavaScript
test with `just js-test tests/js/<name>.test.js`, one e2e spec with
`just e2e tests/<area>/<spec>.spec.ts`.

`just phpunit` runs PHPUnit through ParaTest on 4 processes. Set
`PHPUNIT_PROCESSES` on the host to change the count, for example
`PHPUNIT_PROCESSES=8 just phpunit tests/Module/Board`. Each process uses its own
test database, `app_test<TEST_TOKEN>__p<n>`.

`just js-test` runs Vitest over `tests/js/`. It needs Node alone, with no
container, no database and no browser. It is the fastest way to cover front-end
logic, so put a test there whenever the behaviour is reachable without a booted
application. The suite runs in Node by default. A file that needs a DOM asks for
one with `/** @vitest-environment jsdom */` on its first line.

## The gate

```sh
just cs                    # apply formatter + Rector fixes
just phpstan               # whole project
just arkitect              # whole project
just gamache               # whole project
just phpunit tests/<path>  # the tests for what changed, or --filter <name>
just js-test               # when JavaScript changed
just cli-test              # when cli/ or hooks/ changed
just e2e       # Playwright end-to-end
```

CI's required checks are the full gate. Read them on the pull request, and fix
every failure, including ones that pre-date the change. `just ci` is the full
check-only run in one command. A person can run it in a terminal, and a hook
refuses it for an agent.

**e2e runs against a dedicated target, never the dev host.** `just e2e-up`
creates a disposable database and a sidecar serving this checkout; `just e2e`
refuses to start without it. The suite is destructive by design — one project
truncates every table — so pointing it at your development database wipes it.

No messenger consumer is needed. Messages dispatched during a request carrying
`X-Playwright: 1` are handled inline, so specs that assert on mail or a download
link work with nothing draining the queue.

The suite cannot be parallelised: Mailpit is shared, so mail-asserting specs
across concurrent runs read each other's messages.

## Mutation testing

```sh
just mutation-diff   # only the src/ files this branch changed — run this one
just mutation        # all of src — the weekly Action already does this
```

Infection changes the source code one edit at a time and reruns the tests. A
change that no test catches shows that the test asserts nothing. `infection.json5`
holds the scope, which is all of `src` apart from `Kernel.php`.

Read `var/infection/infection.log` after a run. Escaped mutants name a test that
asserts nothing. Some are harmless, so judge each one. Not-covered mutants name a
line no test reaches, which `--with-uncovered` in both recipes keeps visible.

`just mutation-diff` is the one to run while you work. It mutates the `src/` files
you added or changed against `origin/main`, which takes minutes. Pass another base
as its argument, such as `just mutation-diff main~5`.

A weekly GitHub Action named "Mutation testing" runs the full pass and attaches
its log as an artifact. It is informational, not a required check. That run takes
15 to 25 minutes on a hosted runner and much longer on a development machine, for
the reason below.

### Why a local timing is not a CI timing

A workload that starts one process per unit of work runs about 8 times slower
here than on a hosted runner. Every process autoloads `vendor/` across the
bind mount, and Docker Desktop on macOS serves that mount through a virtual
filesystem. The same suite inside one process is only about 2 times slower.

This holds on an idle machine, so it is not contention. Measure a
process-per-unit tool on CI before you judge how long it takes, or you will
overstate it by an order of magnitude.

Neither is a `just ci` leg. Run either one alone: it starts one PHPUnit process
per mutant against this checkout's test database, and a second test run in the
same checkout crashes on the shared schema.

## Coverage

```sh
just phpunit-coverage        # HTML at var/phpunit-coverage/html, summary on stdout
just open-phpunit-coverage   # open that report
```

This covers the PHPUnit suites. `just e2e-coverage` covers the e2e suite instead,
and it owns `var/coverage`, which it deletes at the start of every run. The two
reports keep separate trees for that reason.

Both recipes take PHPUnit arguments, so `just phpunit-coverage tests/Module/Review`
reports on one directory.

## Reports from CI

```sh
just ci-report mutation             # the weekly mutation run
just ci-report phpunit-coverage     # the weekly PHPUnit coverage run
just ci-report e2e-coverage         # the weekly e2e coverage run
just ci-report e2e-timing [RUN]     # time per e2e project, from any CI run
just ci-report phpunit-timing [RUN] # slowest PHPUnit classes and tests, from any run
```

The recipe downloads the artifact into `var/ci-reports/` and prints its summary.
With no run id, it takes the newest run on `main` that holds the artifact.

The e2e job runs on eight runners, so `e2e-timing` prints one summary per leg.

### Count the flakes of a spec

The `e2e flake hunt` workflow runs chosen specs many times on a CI runner and prints the failure rate of each test. It does not stop at the first failure. Start it from the Actions tab, or with `gh workflow run e2e-flake-hunt.yml -f specs='tests/review/review-loop.spec.ts' -f repeat=20 -f workers=4`. Run it again with `-f workers=2`. Fewer failures with two workers means the runner is overloaded. The same failures means the test is at fault. A spec that registers one fixed account in several tests can collide with its own repeats, and the collisions then show as failures. Check that a spec gives each test its own account before you trust its rate.

GitHub starts a workflow only from `main`, so the workflow must be on `main` first.

A failed e2e job uploads `nginx-log-<leg>`. The last field of each line is the request time in seconds.

## Secrets

```sh
just secrets-scan   # gitleaks + trufflehog over the whole history
```

Not part of `ci` — it needs host tooling and outbound network. Run it before
publishing anything.
