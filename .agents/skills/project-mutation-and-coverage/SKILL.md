---
name: project-mutation-and-coverage
description: "Use when running mutation testing or code coverage, choosing between their just recipes, reading a score or a weekly report, or writing a test that asserts on elapsed wall-clock time."
---

# Mutation testing and coverage

Two weekly GitHub Actions produce these reports. Neither is a required check, and
neither gates a merge. Both write their results to an artifact, so the run page
shows no score.

## The recipes, and where each writes

The recipe names do not make the difference obvious.

| Recipe | Measures | Writes |
|---|---|---|
| `just mutation` | mutation, all of `src` | `var/infection/` |
| `just mutation-diff [BASE]` | mutation, the `src/` files you changed | `var/infection/` |
| `just phpunit-coverage` | coverage, the PHPUnit suites | `var/phpunit-coverage/` |
| `just e2e-coverage` | coverage, the Playwright suite | `var/coverage/` |

A hook refuses a bare `just phpunit-coverage` for an agent. Pass a path, such as
`just phpunit-coverage tests/Module/Review`, or fetch the weekly CI report.

`just e2e-coverage` deletes `var/coverage` at the start of every run. Keep any
report you want out of that directory. `just open-coverage` opens the e2e report.
`just open-phpunit-coverage` opens the PHPUnit report.

Run `just mutation-diff` while you work. It mutates the `src/` files that you
added or changed against `origin/main`, and it includes uncommitted and untracked
files. It takes minutes.

## Never run two of these at once in one worktree

They share one test database. `TEST_SCHEMA_READY` stops each mutant process from
rebuilding the schema. A second run that does rebuild it pulls the schema out from
under the first. The symptom is `duplicate key value violates unique
constraint "pg_type_typname_nsp_index"` in `tests/bootstrap.php`.

To stop a run, first read `docker exec loupe-php-fpm-1 ps aux | grep -E 'infection|phpunit'`.
Then match your own worktree path. A bare `pkill -f phpunit` kills the tests of
every worktree on the machine.

## Fetching a report from CI

The reports exist only as artifacts. Fetch one with `just ci-report`:

```
just ci-report mutation            # summary.log
just ci-report phpunit-coverage    # head of summary.txt
just ci-report e2e-coverage        # head of summary.txt
just ci-report e2e-timing          # per-project time and busy workers
just ci-report e2e-timing <run-id> # any run, a pull request's included
just ci-report phpunit-timing      # slowest PHPUnit classes and tests
```

With no run id, the recipe takes the newest completed run on `main` that still
holds the artifact. It downloads into `var/ci-reports/<artifact>/<run-id>/`,
which git ignores. It does not download a run twice.

| Report | Workflow | Job | Artifact | Holds | Retention |
|---|---|---|---|---|---|
| mutation | `Mutation testing` | `infection` | `infection-report` | `summary.log`, `infection.log` | 90 days |
| PHPUnit coverage | `Coverage report` | `phpunit-coverage` | `phpunit-coverage` | `summary.txt`, `clover.xml`, `html/` | 90 days |
| e2e coverage | `Coverage report` | `e2e-coverage` | `e2e-coverage` | `summary.txt`, `clover.xml`, `html/` | 90 days |
| e2e timing | `CI` | `e2e-<leg>`, one per matrix leg | `e2e-timing-<leg>`, such as `e2e-timing-chromium-3` | `results.json` | 30 days |
| PHPUnit timing | `CI` | `phpunit` | `phpunit-timing` | `junit.xml` | 30 days |

Every CI run makes the e2e timing artifact, green or red. `bin/e2e-timing.mjs`
reads its Playwright JSON report. Time between two tests on one worker counts as
idle, so worker start-up shows as idle time.

The e2e job runs as eight legs on eight runners. A run therefore holds one timing
artifact per leg, and `just ci-report e2e-timing` prints one summary for each.
The legs ran against separate clocks. Read the wall times side by side, and
never add them.

Every CI run also makes the PHPUnit timing artifact. `bin/phpunit-timing.php`
reads the JUnit XML. It prints the mean seconds per test, the slowest classes and
the slowest tests. Its total sums the test times of every ParaTest worker, so it
is not the wall clock of the job. PHPUnit opens the log when the run starts and
writes it when the run ends. A process that dies between the two uploads an empty
file. The script names that case, and does not report zero tests.

A coverage `summary.txt` carries terminal colour codes. A plain `grep` therefore
shows escape characters around the numbers.

Read `summary.log` or `summary.txt` first. A mutation `summary.log` is a few
hundred bytes. A coverage `summary.txt` is about 80 KB, because every class
follows the summary. The HTML tree downloads as about 7.7 MB and expands to
about 87 MB. Fetch it only when you need a per-file breakdown.

A run with no artifact means that the job failed before it wrote one. It does
not mean zero coverage. It does not mean that the upload step is missing. Read
the job log.

A red run on its own still produces a report. `just e2e-coverage` runs its
`phpcov merge` before the exit check, deliberately. A suite that loses a spec
still yields coverage for the specs that passed, and the run still reads as red.
A missing artifact therefore means that the job died before the merge. Typical
causes are an app it could not reach, a failed stylesheet build, or a container
that never came up.

`infection.log` is about 380 KB. It holds the sections `Escaped mutants:`,
`Timed Out mutants:`, `Skipped mutants:` and `Not Covered mutants:`. An escaped
mutant names a test that asserts nothing about a line it covers. Judge each one,
because some are harmless.

## A wall-clock assertion is a landmine

xdebug drives both mutation and coverage, and it roughly doubles execution time.

Never assert an absolute elapsed time in a test. `MarkdownRendererTest` asserted
that a render finished in under 5 seconds. It took about 2.5s normally and 5.4s
under coverage. It failed the initial coverage run and aborted Infection before
a single mutant ran. The failure reads as a broken Infection, not as one
unrelated test. It breaks a coverage run the same way.

Assert a ratio instead. Run the work at two input sizes and compare the times:

```php
[$quarterElapsed] = self::timeRender($renderer, 5_000);
[$fullElapsed, $html] = self::timeRender($renderer, 20_000);

// Four times the headings: linear takes 4x, quadratic 16x.
self::assertLessThan(8.0, $fullElapsed / $quarterElapsed);
```

Coverage slows both renders by the same factor, so the ratio survives it. A
loaded machine can slow one render and not the other. Keep the bound far from
both growth rates. With sizes of 10,000 and 20,000 and a bound of 3.0, one
`just ci` on a loaded machine measured 3.16 and failed.

Playwright carries the same kind of bound in its own config. Its `expect`
timeout is an absolute 5 seconds. Per-request collection takes one page render
from about 0.5s to about 4.7s. A coverage run therefore sits on that bound, and
fails on timeouts, not on anything a spec asserts. `playwright.config.ts` raises
the timeouts when `COVERAGE` is set. The per-pull-request gate keeps 5s.

## Never size a run from a local timing

A workload that starts one process per unit of work runs about 8 times slower on
a development Mac than on a hosted runner. Every process autoloads `vendor/`
across the bind mount, and Docker Desktop serves that mount through a virtual
filesystem. The same suite inside one process is only about 2 times slower.

This holds on an idle machine, so a wait for a quiet machine does not fix it. A
local projection of the full mutation run said 1h 22m. The runner does it in 15
to 25 minutes.

Two runs of identical work on identical runners differed by 1.8x. Read one
hosted timing as a sample. Set `timeout-minutes` from a measurement, at a few
multiples of it. A loose timeout turns a degraded run into an hour of runner time
that nobody watches.

## These tools fail with a signal that looks valid

Every failure here so far has produced an answer that a reader would accept.
Distrust a number from a run that you have not confirmed finished.

| What you see | What it means |
|---|---|
| A fast, complete-looking mutation run | It may have crashed in cleanup after printing the metrics. Check the exit status. |
| `ini_get("xdebug.mode")` says `develop` | Nothing. xdebug honours the `XDEBUG_MODE` environment variable, and `ini_get` reports the ini file. Read `getenv`. |
| No artifact on a run | The job failed before writing one. It is not zero coverage. |
| `Command terminated by signal 7` | The container died on host memory, past PHP's own limit. No PHP error and no stack trace. |

## Raising PHPUnit's memory limit

`php -d memory_limit=...` does not reach PHPUnit. `phpunit.dist.xml` sets the
limit with an `<ini>` directive. PHPUnit applies it with `ini_set()` after
startup, so the command line loses. A run started with `-d memory_limit=4G`
still died at exactly 536870912 bytes. Change the value in `phpunit.dist.xml`.
This also raises the ceiling for `just phpunit` and the gate, which gives a
memory regression more room to hide.

This project does not use `--path-coverage`. Path coverage held 492 MB for 51
tests and killed the container at about 250 of 2,262 tests, so it does not fit
this suite.

## Infection needs 2 GB

Both recipes run Infection under `php -d memory_limit=2G`. Infection deletes
about 8,500 mutant directories when it finishes, and `Filesystem::remove()`
materialises that tree before it deletes it. At 512M the process dies in the
cleanup, after it writes the reports, so the failure looks like something else.
