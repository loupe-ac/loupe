---
name: working-with-prs
description: "Use when opening, gating, reviewing or merging a pull request, writing its body, making a branch testable, handling agent review findings, or sequencing several branches at once."
---

# Working with pull requests

Every change reaches `main` through a pull request. `main` is protected, so no
path skips this.

## The gate, before you open anything

1. `just cs` applies the formatter and rector fixes. Commit anything it
   changed. It works from a worktree. The finder uses explicit excludes and
   throws if it matches zero files, so a vacuous pass is not possible.
2. Run `just phpstan`, `just arkitect` and `just gamache`. These check the
   whole project.
3. Run PHPUnit on the tests for what changed: `just phpunit tests/<path>` or
   `just phpunit --filter <name>`.
4. Run `just js-test` when JavaScript changed, and `just cli-test` when `cli/`
   or `hooks/` changed.
5. Push. Run no Codex review. A separate review worker reviews the pull
   request after the push (see "The agent review"). CI's required checks are
   the full gate. Read them on the pull request, and fix every failure,
   including ones that pre-date your change.

A `PreToolUse` hook, `.agents/hooks/no-full-ci.sh`, refuses `just ci` and a
full PHPUnit run for an agent. `just audit` is not in the local list, because
CI runs it. A worker learns of a failed required check from the board. The
`checks-failed` fix round covers every required check.

e2e is not in the local gate. The eight `e2e-*` checks on the pull request gate
the suite. Do not run the full suite locally before you open the pull request.
"Running the suite locally is debugging, not gating" lists the legs, and the
cases that still want a local run.

### The agent review

Open the pull request as a draft. A review worker reads the whole change at the
head commit. That worker is never the worker that wrote the code. Loupe posts
the result as the check `Loupe / Agent review`, with each finding beside its
line. `docs/using/workflows.md` "Agent review" describes the flow.

- A failed review asks for a fix at once. The fix round reads the findings of
  the head commit from `card_get`, in `agentReview`.
- When the review passes and CI is green, Loupe marks the pull request ready.
  The card then moves to In review. Never run `gh pr ready` for it yourself.
- The workflow of the project must hold the agent review rules, as Lifecycle
  does. Without them, Loupe marks nothing ready and the draft stays a draft.
  Tell the owner in the body when you open a draft in such a project.
- Review the diff against `origin/<base>`, never `main`. The local `main` of a
  worktree is often stale, so a review against it reports findings for
  already-merged code.
- A reviewer inside an implementation run still runs, such as the per-task
  reviewer of the build notes. Those reviews are part of the build.

### The Design System artifact rebuild

After the gate checks, run `bin/agents/design-system-changed origin/<base>`. The script prints the changed design system paths. It prints nothing when none changed.

When it prints a path, try the rebuild with the `loupe-design-rebuild` skill. Do not skip this step.

When the rebuild cannot write, hand it to the owner:

1. Write the line `Design system rebuild: pending` in the pull request body. A rebuild cannot write when the `Artifact` tool is missing, as in a headless worker.
2. Call `inbox_search` with `Design system rebuild`. Stop here when a row with state `open` asks for the rebuild. One rebuild covers every pull request that is pending, so a second item only adds noise.
3. Otherwise call `inbox_ask` with one `todo` item titled `Rebuild the Design System artifact`. Set `blocking` to `false`, so the ask closes at once and does not end your turn. Link the card in `cardIds`.
   Suggest one `start-session` action with the `cardId` of that card and `recommended: true`. Give it a one-line `reason`.
   Set its `prompt` to: "Use the loupe-design-rebuild skill in this main checkout. First check that the branch is `main`, and run `git pull --ff-only`."
   The `loupe-inbox` skill gives the full parameters.
4. Leave the card and the pull request out of the title. The `Design system rebuild: pending` line on each pull request names the work.

## A check is a fault until you have seen it fail

A check that comes back clean is a fault in the check, until it has failed on a
known-bad input.

A check that comes back one short is a fault in the check, until you prove
otherwise.

Nobody tests the second one, because a check that reports a problem looks
self-justifying.

A tool reports what it did. It does not report what it found. Doing nothing
successfully and finding nothing wrong produce the same output. So break the
check on purpose, and watch it go red. Then restore it and trust the green.

Confirm that the break landed before you read the result. One session broke a
rule with a `perl` one-liner whose escaping was wrong. The file did not change,
the suite stayed green, and that green read as "the rule is fine". `git diff
--quiet` caught it. A falsifier that does nothing is the same fault one level
up, and it is the more convincing of the two.

| Signal | What it cannot distinguish |
|---|---|
| `phpstan` exit 0 | a clean tree from a rule that never loaded |
| a test that pins behaviour | the behaviour existing from the test not asserting it |
| `just cs` reporting success | a fixer that ran from a rule that silently no-opped |
| a conflict report naming one file | the other files being unchanged from being reverted |
| `pass=10` on a pull request | a current head from a head that has since moved |

Four sessions hit this about fifteen times in one evening on 2026-09-11. The
falsifier broke it each time. One falsifier was a file that called a constructor
with no arguments, so the arity error proved that the rule runs. Another added
the forbidden filter to the listener, so the test that forbids it went red.

Grep is where the second direction bites. A grep for the five `var/tailwind`
negation rules in `.gitignore` matched four, because the pattern could not match
a bare `!/var/`. All five were present. Two sessions made that mistake on the
same five lines, with different patterns, forty minutes apart. Read the region.
Do not grep for what you expect to find.

### Prove a resolution lost nothing

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

### A stacked branch conflicts after its parent squashes

A branch stacked on a pull request that merged by squash conflicts in files that
it is a strict superset of. The squash gives `main` the same content under a new
commit that shares no history with the branch. Expect this at every stack merge.
Retarget the child to `main` first, as "Open a stacked pull request against its
parent's branch" says.

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

### Ask what reads what you write

Inert code looks wired up when its input is present. A changelog fold proposed
for `docker/prod/release.sh` reads as plausible, because `COPY . .` puts
`docs/CHANGELOG.md` into the production image. Nothing reads it there.
`grep -rn CHANGELOG src/ templates/ config/ public/` returns nothing, and the
container filesystem is discarded on each deploy. The fold would have run,
reported success and changed nothing.

For any step that writes something, name what reads it. Require a grep that
finds the reader. An input that exists is not evidence that anything consumes
the output.

## Documentation-only branches run `just cs` and push

Skip the local checks. Say so in the PR body, so the record shows that the gate
was reduced deliberately and not forgotten. CI still runs its own checks,
including the e2e shards, and the agent review still reads the pull request.

The test is the diff, not the intent. Every changed file must end in `.md`.
Check it, and do not assume:

```bash
git diff --name-only origin/main...HEAD | grep -v '\.md$'
```

Any output at all means that the full gate applies. A branch that also touches
`.env`, a Twig template, a fixture, `composer.json` or a `justfile` recipe is
not documentation-only, however small the change looks.

CI still runs every check, because Markdown is not inert here. Prettier covers
some of it, the gamache checks read `docs/`, and a docs commit can break a build
that greps them.

The reduced gate does not extend to Markdown that a script *executes*. A `.md`
file that a script parses for commands is code with a `.md` suffix, so gate it
fully. A `SKILL.md` sits at the edge. An agent reads it and no script parses it,
so the reduced gate applies. Note that call on the gate line.

The reason is proportion. A gate that can never fail teaches a reader to stop
trusting gate results.

## Open it as a draft

Open every pull request as a draft: `gh pr create --draft`. The agent review
runs on the draft. When the review passes and CI is green, Loupe marks the
pull request ready, and the owner then sees it. Do not run `gh pr ready`. Do not
wait for the review of the owner to un-draft it.

A draft that stays a draft after a passed review and green checks is a fault of
the setup. The usual cause is a workflow with no agent review rules, or a
bridge with no `review` work entry. Say so in the body. Ready does not mean
merged. `main` still needs one approving review, and you never approve your own
work.

## Keep the body brief

The owner reads every body. Write it so that he can read it in under a minute.
Put the full reasoning in the commit messages, because the squash keeps them in
`git log`.

Aim for about 100 words on a small change, and stay under 250 on a large one.
Use short bullets. Do not use paragraphs.

Write only these, and leave out any line that has nothing to say:

- Preview links, at the top. Every pull request has them.
- The URL of the board card the branch works, when a card exists.
- One or two sentences on what changed and why.
- The rejected alternative, in one sentence, when there was a real one.
- What you verified, one line per check.
- What you could not verify.
- A decision the owner must make.
- A deploy need, a breaking change, or a merge order.
- One gate line, such as "Gate: cs, phpstan, arkitect, gamache and
  `tests/Module/Board` green." Name a reduced gate on that line.

Leave these out:

- A heading for every item. `## Preview` is the only heading a body needs.
- Any line that says something does not apply: "nothing to click", "no deploy
  need", "no changelog fragment", "the gate was not reduced".
- The diff restated in prose, a code block of the change, or a table per file.
- A narrative of how you got there. That goes in the commit message.

A reviewer asks for detail when he needs it. Answer in a comment. Do not grow
the body.

## Make the branch testable, not just reviewable

A reviewer who has to build state by hand usually will not.

Put the preview links at the very top of the body, under a `## Preview`
heading. They go before the decision, the summary and everything else. They are
the first thing the owner looks for, so nothing goes above them. A demo section
two thirds of the way down, next to the gate results, makes the reviewer hunt.
"Try it", "Click it" and "Verification" are typical names for such a section.
Lead with the links, then explain the change.

Give each link a one-line label that says what state it shows. A child of an
epic also writes a `State:` text on each line, as "A child of an epic targets
the epic branch" says. When a change has several states, seed one document per
state and link each. A reviewer who can see all of them side by side reviews
what the code does. Otherwise the reviewer sees only the one case you seeded.

Point at the running instance, and sign the reader in. Every worktree serves its
own branch at `https://<slug>.loupe.dev.localhost`. Do not put a bare page URL
in the body. It lands the reader on the login page, and the reader must sign in
by hand. Mint a signed link instead, from inside the worktree:

```bash
( cd .worktrees/<name> && bin/worktrees/compose-exec.sh \
    bin/console app:dev:preview-login-link --path=/projects )
```

The link signs the reader in and lands them on the page you named, so the body
needs no credentials at all. Use `--email=admin@loupe.test` for an admin page.
The link does not expire, so it still works when the reviewer reads the body
days later. The signature covers the whole URL, host included. A link therefore
works against that worktree only, and the host resolves on your own machine.
The route is `#[When('dev')]`, so it does not exist in production.

Open the link yourself before you write it down. Seed and prove every link
before the push, not after it. A branch that cannot show its marker is not
pushed as ready.

### Prove each preview link shows its state

A headless session cannot look at a page, so it proves each link with `curl`.
Do this before you write the body. Pick a marker that the diff adds to the
page: a translated label, a data attribute or a CSS class. A marker that the
page showed before the branch proves nothing.

```bash
link=$( ( cd .worktrees/<name> && bin/worktrees/compose-exec.sh \
    bin/console app:dev:preview-login-link --path=/projects ) | tail -1 )
jar=$(mktemp) page=$(mktemp)
/usr/bin/curl -sSL -c "$jar" -b "$jar" -o "$page" \
    -w '%{http_code} %{url_effective}\n' "$link"
grep -c 'Your projects' "$page"
```

The link signs the reader in, sets a session cookie and redirects. Without the
cookie jar, the redirect lands on `/login`. The macOS `/usr/bin/curl` trusts the
local certificate authority through the keychain, so it needs no `-k`.

Read two results. The effective URL must end in the `--path` you minted, or the
sign-in failed. The count must be 1 or more, or the state is not seeded. For a
signed link, fetch the bare page URL once without the link, and confirm that
the count is 0.

Write one Markdown list item per link in the Preview section. Name the marker
that you found:

```markdown
- [Board, empty Next column](https://card-42.loupe.dev.localhost/dev/preview-login?…), marker `lp-empty-state`
```

A page that only a signed-out visitor sees, such as the landing page at `/`,
takes a plain link. A signed link signs the reader in, and the page then
redirects. Prove the plain link with the same `curl`, with no cookie jar, and
end the line with `. Signed out.`. The plain link is the bare page URL, so skip
the zero-count fetch. The effective URL must end in the path, and the count
must be 1 or more. The marker must not be on `main`:

```markdown
- [Landing page](https://card-42.loupe.dev.localhost/), marker `rel="help"`. Signed out.
```

A child of an epic appends `. State: <text>` to the line, so a signed-out line
ends `. Signed out. State: <text>`. A link to a file, an artifact or a CI run
takes the same shape with no marker. So does the card line:
`- [Card 42: <title>](<card URL>)`.

A branch that changes a page and cannot show its marker is not ready.
"The tests cover it", "the seed holds no X" and "it shows after a bridge
reports data" do not replace a seeded state.

Every pull request has a Preview section. When a branch changes no page, link
what the reviewer reads instead. That is each changed file rendered on GitHub,
a published artifact, or the CI run that shows the change working. Pin a GitHub
link to the pushed commit SHA, and not to the branch name. The link then shows
the code under review after the branch moves.

Put the card URL on its own line under the Preview section, when a card exists.
`loupe-board` says how to build that URL and how to add the pull request to the
card. Keep the card line when you strip the Preview section, because a card URL
stays valid after the merge.

Every such URL must be clickable. That means one whole absolute URL in plain
text. A body with several pages to point at tends to give a bare host followed
by paths in backticks. That renders as unclickable code spans, and the reviewer
has to assemble each URL by hand. That friction is enough to lose the click
that the seeding was for. So follow these rules:

- Put no backticks around a URL.
- Write no relative paths under a host that you give once.
- Leave no `<slug>` or other placeholder for the reader to substitute.
- Write the full `https://<slug>.loupe.dev.localhost/projects/…/review` per
  destination, even when that repeats the host five times.
- Paste one into a browser before you open the pull request.

Strip the `## Preview` section out of the body before you merge. `main`
squashes, so the body becomes the permanent commit message. A signed
`*.dev.localhost` link points at a worktree that is torn down within hours.
Name the commit that the preview was built from, so a later reader knows which
code the links showed. Never write "this branch's head". The phrase is true of
every branch at every moment, so no reader can check it. An epic child keeps its
`## Preview` section, because the merge stage copies its links to the epic pull
request after the merge.

The same rule covers a branch with no worktree of its own. Link the page on
whatever instance does serve it. Do not describe the route and leave the reader
to construct it.

"Nothing to click" is a claim about the options of the reviewer, not about
routing. A branch that adds no route can still produce reviewable output, such
as HTML from a renderer, a generated report or a file. To say that it has
nothing to look at because nothing is wired up is wrong. It also reads as
though nobody can judge the work until a later branch lands. Publish the output
as an artifact and link it. Say which questions it answers and which it defers.

Seed the data the change needs. `bin/console app:dev:seed` creates a user, an
admin and a project, and no documents, comments, verdicts or exports. A diff
feature is untestable without a document that carries several versions. Create
fixtures that contain the exact shape the bug had, not merely a happy path.
Tell the reviewer which one to open and what to look for.

Seed with a temporary `#[When('dev')]` console command created inside the
worktree. Run it, delete it, then confirm that `git status --porcelain` is
empty. Fixtures must never reach the branch.

## Reviewing work you did not write

A green gate is not evidence that the change is correct. One wave had six
branches, all green on `just cs`, `just ci` and e2e. A review found a real
defect in every one, and two of them could lose data. The gate proves that the
suite passes. It does not prove that the change is right.

So read the diff yourself, especially the part that the author called routine.
An agent can report a decision that it made on your behalf. Check whether that
decision is orthogonal to the open questions it claims not to touch. One wave
branch added a unique index and called it unrelated to a design decision that
it foreclosed.

Send findings back to whoever wrote the code, not to a fresh agent. The author
still has the context. The second-order questions, such as "does anything else
in this file have the same shape?", catch the bug the reviewer did not spot.
Ask for a reproduction of the failure against the pre-fix code. You then know
that the new test discriminates and does not pass vacuously.

## Running the suite locally is debugging, not gating

The eight `e2e-*` legs are the gate: `e2e-chromium`, `e2e-chromium-2` to
`e2e-chromium-6`, `e2e-rest` and `e2e-global-flags`. They run the same
`just e2e` against a disposable stack, with both `E2E_BASE_URL` and
`MAILPIT_URL` set correctly. See `.github/workflows/ci.yml`.

The six chromium legs split that project per test. `rest` runs admin and the
destructive chain, and `global-flags` runs alone. Each leg is on its own runner
with its own stack. Read the red leg for the failure. `maxFailures: 1` is per
process, so a red run can report one failure in each leg.

Each leg is a required check only after the owner adds it to the main ruleset.
On `main`, the `lint` job fails and names each leg that the ruleset does not
require.

Locally the same suite is slower, destructive, and measurably less truthful.
In one wave of five branches, every local e2e problem was environmental, and CI
passed all five. Three runs failed on the `MAILPIT_URL` omission below. One
failed on cross-worktree contention that CI then cleared. A gate that fails for
reasons unrelated to the diff is worse than no local gate. Someone has to spend
judgement to decide which failures to believe.

So push, then read the check. Fix every failure it reports, pre-existing ones
included.

Run it locally when you work on a spec, not when you finish a branch. One case
earns it: a named spec that you change or debug, with
`just e2e tests/<area>/<spec>.spec.ts`. It is fast, and it is the only way to
iterate on a selector, which neither `just lint` nor `just phpstan` exercises.

Nothing earns a full local run, including a branch that you cannot push yet.
The `.agents/hooks/no-full-e2e.sh` hook refuses one.

### Aiming a local run at a sibling worktree

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

## Merging

The `main` ruleset sets `strict_required_status_checks_policy` to `true`, so
GitHub refuses a merge from a branch that is behind `main`. The sync below is a
hard block, not a caution. Every merge to `main` puts every other open branch
behind, so a queue of N branches costs N sync-and-gate cycles. Two sessions
cannot interleave their merges, so one session holds the queue at a time.

1. The final gate of a branch happens on the branch fully merged with current
   main. A branch cut before a sibling merged has not really been gated. Its
   e2e run never exercised the specs of the sibling against its code.
2. Merge immediately after the gate goes green. Any merge to `main` between the
   last sync of a branch and its own merge invalidates its PR through
   conflicts, or invalidates its gate.
3. After every merge, run `just cs` on main and commit the drift. A merge of two
   individually clean branches produces fixer drift. Otherwise that drift lands
   on whichever branch syncs next. The changelog needs nothing from you, because
   the image build folds the fragments itself.
4. Tear down the worktree of a merged branch only after `gh pr merge` is
   confirmed. Never do it in the same command chain. Confirm the merge by reading
   the result, not by having issued the command. A merge can fail for a reason
   that is not a conflict, such as mergeability still computing or a check that
   flipped. A tree destroyed first turns a retry into a rebuild from origin.
   Nothing is lost while the branch is still on origin, so verify that before
   anything else.
5. A conflict-free merge is not a correct merge. When one branch renames a
   class, a *new* file on another branch merges cleanly while it still imports
   the old name. Git sees no conflict, because the file never existed on both
   sides. Push the merged result and read CI before you trust such a merge.
   phpstan catches this, and git does not.

   The signature variant is the same blind spot with a different tell. A branch
   that makes an argument required lives on and absorbs merges. Each incoming
   merge can bring a *new* file from a sibling that constructs the old shape.
   The file existed on only one side, so git has nothing to report, and the
   result is a runtime `ArgumentCountError`. A grep for the changed symbol is a
   good first pass, and it is not sufficient. It finds only the shapes you
   thought to search for, and it goes stale at the next merge.

   A formatter that reads a signature belongs to the same family. `just cs`
   moved a `number:` argument to the last position on one branch. The `Card`
   class of that branch had no `$number` property, because the parent branch
   added it. The rule is Rector's built-in `SortCallLikeNamedArgsRector`. It
   comes through `GamacheSetList::CONVENTIONS` and is declared in
   `vendor/ubermuda/gamache/src/Rector/config/conventions.php`, so a grep of
   `rector.php` finds nothing. It sorts by `$order[$name] ?? \PHP_INT_MAX`, so a
   name that the constructor does not carry sorts last.

   An unchanged file does not prove that the ordering is correct. The rule bails
   out when it cannot resolve the class, and a silent no-op reads exactly like a
   pass. A green `cs-check` dry-run does not prove it either. On one branch the
   dry-run flagged nothing, and `just cs` then reordered that file with the same
   rule set in both legs.

   Verify on a detached HEAD that merges the branch onto its parent. Run the
   tests there, then return with the branch ref unmoved. That tests the change
   in its destination, not in the tree it was written in. Read the version of
   one file on the parent with `git show origin/<parent>:<path>`, which has no
   effect on the cut point.
6. A resolution that takes one side can silently revert the fix of the other
   side. Before you accept a resolution, re-verify the *behaviour* that both
   branches were protecting. The absence of markers is not enough. The sharpest
   form is rename/rename. There neither side is correct, and the answer is the
   content of the newer branch at the path of the other branch.
7. "Pull Request is not mergeable" right after `git push` usually means GitHub
   has not recomputed mergeability yet. Wait a few seconds and retry.
8. Read the checks against the head you are about to merge. After you sync a
   branch, the rollup can still describe the *previous* head. A green reading
   is then evidence about a commit that no longer matters. Key the wait on
   `headRefOid`, then count the checks:

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
   denominator cannot go stale the way a written list does. It prints an
   explicit `pending` bucket, so it avoids the field problem of the raw rollup.
   Its `--json` mode exits 0 while checks are pending, so read the counts and
   not the exit code.

   `gh pr view --json statusCheckRollup` gives you the raw rollup, and it needs
   care. `references/reading-the-rollup.md` holds the jq expression that reads
   it, and the test for any replacement.

   A rollup with fewer entries than the ruleset requires is the same hole one
   level up. A run that has not registered reads the same as "nothing left to
   wait for". Counting closes both.

## The changelog entry rides the pull request it describes

Every pull request that changes something a reader can act on carries its own
changelog fragment, in its own branch, at `changelog.d/<number>.md`. Write it as
you write the change. Do not open a second pull request for it. Never edit
`docs/CHANGELOG.md` itself.

Name the file after the pull request number, and anchor the entry to the same
number. `changelog.d/209.md` reads:

```
- (#209) — **Fixed:** what changed, from the reader's side.
```

The number exists as soon as you open the pull request. The squash SHA does
not, which is why the old rule sent the entry to a later branch. The subject of
the squash commit ends with `(#209)`. `git log --first-parent --grep='(#209)$'`
therefore recovers the commit from the number, and
`gh pr view 209 --json mergeCommit` gives the SHA directly. Entries written
before this rule carry a SHA as well. Leave them alone.

Keep the `$` on that grep. `--grep` reads the whole commit message, and a body
that cites another pull request in the same `(#209)` form matches as well. The
log already holds 50 such citations, and `--grep='(#371)'` returns five commits
with the real one last. The anchor ties the match to the end of the subject
line, where the squash number lives, and returns exactly one.

Re-read your own entry when review changes what the branch does. The entry now
rides the branch, so a feature cut in review leaves a line that describes work
that never shipped. It is your own diff, which is the one you stop reading.

One fragment per pull request means that two branches never write one file, so
two entries never conflict. That replaces the old rule, which put every entry at
the top of `[Unreleased]` and made the conflict certain. On 2026-09-11 three
merges each turned every remaining branch CONFLICTING. `docs/CHANGELOG.md` was
almost always the only conflicted file.

The fragments reach a reader twice, and neither step is yours. The image build
folds them on every deploy. The `docs` stage of `docker/prod/Dockerfile` runs
`php bin/changelog.php --keep` before Astro reads `docs/`. The published
changelog therefore carries every merged fragment, and the files stay where they
are. `just changelog` folds them into the committed file and deletes them, which
is the release step.

`php bin/changelog.php --check` reads the fragments and reports a malformed one.
`just lint` runs it, so the gate catches a bad anchor on the branch that wrote
it. The check reads format only. Nothing asks whether a branch carries a
fragment at all, and that rule belongs in a pull request on
https://github.com/ubermuda/gamache.

An older entry keeps the position that its SHA gives it in
`git log --first-parent`.

Tag it with one of the six tags Keep a Changelog defines: `Added`, `Changed`,
`Deprecated`, `Removed`, `Fixed` or `Security`. `bin/changelog.php` rejects any
other word. Write one sentence that says what changed from the reader's side.
Leave the reasoning to the pull request body.

Write one entry per pull request, not one per branch. A branch that shipped six
features earns six lines. A reader who looks for when tags arrived should find a
line about tags, not a paragraph about the wave that contained them.

A pull request whose whole diff is `docs/CHANGELOG.md` and `changelog.d/` earns
no fragment. A fold is the usual case. The exemption keys on content, not on
subject. A pull request about changelog discipline that changes a skill file
does earn one, and it carries that fragment itself.

To read an older entry, you match a 7-character SHA. Derive it with
`git rev-parse --short=7`, never with `git log --format=%h`. `%h` honours
`core.abbrev`, which is unset here, so git picks a length from the object count
and returns 8 today. A grep built on `%h` reports zero hits on every commit,
which reads as a file that records nothing. A row of zeros is a fault in the
check before it is a hole in the file.

## What the ruleset actually requires

`main` allows `--squash` only. A plain `gh pr merge` fails with
`GraphQL: Merge commits are not allowed on this repository`. Use
`gh pr merge <n> --squash`.

Do not diagnose a rejected merge with `gh repo view`. It reports repository
*settings*. These say that all three merge methods are allowed, while the
ruleset forbids two. The ruleset is the authority:

```bash
id=$(gh api repos/loupe-ac/loupe/rulesets -q '.[]|select(.name=="main")|.id')
gh api repos/loupe-ac/loupe/rulesets/$id -q '.rules[]|select(.type=="pull_request")|.parameters'
```

That prints one approving review. It also sets `require_code_owner_review`,
which is inert while the repository has no `CODEOWNERS` file. Read the required
checks from the same ruleset, named `main`:

```bash
gh api repos/loupe-ac/loupe/rulesets/$id \
  -q '.rules[]|select(.type=="required_status_checks")|.parameters.required_status_checks[].context'
```

On 2026-10-06 it printed twelve contexts: `lint`, `cs-check`, `phpstan`,
`arkitect`, `gamache`, `audit`, `phpunit`, `js-test`, `cli-test`,
`e2e-chromium`, `e2e-rest` and `e2e-chromium-2`. Run the command, and do not
trust that snapshot. It read ten before the e2e shard jobs arrived, and
thirteen while a fan-in `e2e` job also ran. Nothing in the repository fails
when it goes stale.

No ruleset covers `refs/heads/epic/*` since 2026-10-02. Loupe gives a pull
request into `epic/<n>` the required checks of `main`, and the merge stage
checks an epic child against them by name:

```bash
gh api repos/loupe-ac/loupe/rules/branches/main \
  -q '.[]|select(.type=="required_status_checks")|.parameters.required_status_checks[].context'
gh pr checks <n> --json name,bucket
```

Each name must have a check in the `pass` bucket. A direct push to `epic/<n>`
works, so the epic branch syncs by a merge of `main`, and a fix round pushes
to it. Never force-push it.

An approval in chat is not a GitHub approval. Check it before you conclude that
something else blocks a merge:

```bash
gh pr view <n> --json reviewDecision,mergeStateStatus
```

A job that runs in CI but is not in that list cannot block anything. To add one
is a repository setting, so a branch cannot do it. If you add a CI job, say in
the PR body that requiring it is still owed. Otherwise the job is decoration.

Never approve your own work. The review itself stays with a human.

Merging does not stay with a human. A PR that is *approved* with *all required
checks green* is good to merge. Merge it without asking, when no workflow rule
will merge it. A PR linked to a card belongs to the `merge-ready` rule, so leave
it to that rule. Hand-merge only a PR that no rule reaches. The approval already
carried the decision, and a wait for a second confirmation parks finished work
in a queue. Follow the merge protocol above when you hand-merge one. Sync with
current main, let the checks re-run on the synced head, merge, then run
`just cs` on main.

Never merge something unapproved. Never merge past a failing or pending check.
Never use `--admin` to bypass either.

## Keep working, and stack when you are waiting

Do not stop because a pull request is unmerged. Stack on it and carry on.
Stacking is the recommendation of the owner, and a wait for an approval is not a
block. Branch off the pull request your work needs, and build the next piece.
Say in the body which branch it stands on and why.

Genuinely blocked means that there is nothing you can do. Examples are a
decision only the owner can make, a credential you do not have, or a service
that is down. An unapproved pull request is none of those. Neither is a queue.

This overrides the caution that a session may carry from a bad wave. One wave
of five stacked board branches went wrong, and was collapsed into a single pull
request. That history says what to watch, not what to avoid. Every failure in it
was a merge that git could not see:

1. A formatter reversed a fix because the branch could not see the signature of
   its parent.
2. A required constructor parameter broke four files with no conflict reported.
3. A `CONFLICTING` pull request never ran CI at all, because `pull_request`
   does not fire without a merge commit.

So the discipline is merge-protocol item 5, not abstinence. Push the merged
result and read CI. Do not trust a clean `git merge`. Check that the stack is
not `CONFLICTING` before you believe a green check.

Say in the body which pull request the branch stacks on. The reviewer needs it,
and so does whoever holds the merge queue. A stack has an order, and approvals
that arrive out of sequence do not respect it.

### Open a stacked pull request against its parent's branch

A stacked pull request targets the branch of the pull request it stacks on.
Never target `main` from it. Against `main`, GitHub shows the commits of the
parent as part of the child. One stacked branch showed 272 changed files
against `main`, and 205 against its parent.

```bash
gh pr create --base <parent-branch> --title "<title>" --body-file <file>
gh pr edit <n> --base <parent-branch>   # fix one opened against main
```

- Write `Stacks on #<parent>. Merge #<parent> first.` near the top of the body.
- CI runs on every pull request, whatever its base. A pull request into an
  `epic/*` branch has no required checks on GitHub, so `--required` fails
  there. The merge stage checks it by name against the required checks of
  `main`. A pull request stacked on any other branch has no required checks.
  Read `gh pr checks <n>` without `--required`, and count every check.
- Never merge the child while it targets the branch of its parent. That merge
  lands on the parent branch with no required check, and never reaches `main`.
  An epic child that targets `epic/<n>` is the exception, as "A child of an epic
  targets the epic branch" says.

When the parent squash-merges, the child still targets the branch of the
parent. This repository keeps a merged branch (`delete_branch_on_merge` is
`false`), so GitHub does not retarget the child. Do it yourself:

```bash
gh pr edit <n> --base main
git fetch origin && git merge origin/main
```

Merge `main` in. Never rebase a branch that holds merge commits. Expect
conflicts in files that the child only extends, as "A stacked branch conflicts
after its parent squashes" says, and prove the resolution there.

### A child of an epic targets the epic branch

When an epic has an `epic/<n>` branch, each child cuts its branch from
`origin/epic/<n>`. Its pull request targets `epic/<n>`, never `main` and never
the branch of a sibling.

This child is not a stacked pull request in the sense above. The merge stage
squash-merges it into `epic/<n>` with no approval, once its required checks
pass. No ruleset covers `epic/<n>`, so GitHub sends no behind event. The
stage compares the child with `epic/<n>`, and merges `epic/<n>` in when the
child is behind.

The epic pull request from `epic/<n>` into `main` needs the approval of the
owner, as any pull request into `main` does. It syncs by a merge of `main`,
through `gh pr update-branch` or the app sync, and the approval covers that
merge. An epic whose breakdown ran before the epic branch existed has no
`epic/<n>`, and its children target `main`.

The app opens the epic pull request as a draft after the first child merge.
While the epic sits in In review, an `epic-preview` work request on the epic
card has the merge stage refresh the epic preview. The preview is at
`https://epic-<n>.loupe.dev.localhost`, and the refresh runs once for each head
of the epic pull request. The stage carries the preview links of the children
to the `## Preview` section of the epic pull request. It re-mints each signed
link from its `to` and `email` parameters. It carries a plain signed-out link
by its path, and proves it on the epic preview with no cookie jar.

So write each Preview line of a child with a signed link, or a plain link for a
signed-out page. Add its marker in backticks, and a `State:` text. The database
of the child goes at teardown, and the stage seeds each state again on the epic
preview from that text alone. So name every record that the page needs, with
its counts, its flags and the account that owns it. For example: "State:
project owned by dev@loupe.test, 2 open inbox items, readiness guide not
hidden". A line with no `State:` text, or a state that the stage cannot build,
reads "not proved" on the epic pull request.

## Running several branches at once

Give each branch its own worktree, and keep the branches off the files of each
other. Two shared files used to collide on every branch, and neither does now.
Open work lives on the board, not in the branch. A changelog entry lives in
`changelog.d/<number>.md`, not at one append point in `docs/CHANGELOG.md`.

Sequence by blast radius. A branch that changes shared infrastructure, such as
compose files, environment resolution or CI wiring, should merge last. Its merge
invalidates the environment that its siblings are still gated in. Say so in its
body.

Land signature changes early. A branch that makes an argument required grows
more dangerous with every sibling merged ahead of it. Each merge can bring a new
file that constructs the old shape (see merge protocol item 5).

Batch by file overlap, not by theme. Two agents that edit the same module
conflict, however unrelated their tasks sound.
