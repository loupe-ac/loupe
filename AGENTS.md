# AGENTS.md

This file guides any coding agent that works in this repository.

## Topic-specific guidance

These skills hold the detailed conventions for one area each. Invoke the relevant skill before you write or edit anything in that area. There are no exceptions. "I already know the pattern" and "this is a small change" are not exceptions, because the skills carry project-specific conventions that differ from the defaults and from each other.

A skill's name prefix says which side of the product you stand on. A `loupe-*` skill covers using the Loupe product through the `loupe` MCP, a `project-*` skill covers developing this codebase, and a `symfony-*` skill covers generic framework mechanics that any Symfony project shares. `project-site-review` and `loupe-site-review` show the split: one builds the widget, the other acts on the feedback the widget produces.

| Skill | Use when working on… |
|---|---|
| `project-backend` | PHP code under `src/`: forms, DTOs, entities, controllers, flash messages, the gamache layers and their configuration, email, CSRF |
| `project-command-handler` | Command and handler pairs, any controller action with business logic, `DomainErrors` |
| `project-authz` | Voters, `#[IsGranted]`, Mercure topic authorization, any access control |
| `project-testing` | PHPUnit tests: unit, integration, WebTestCase, mock setup, database isolation between tests |
| `project-e2e` | Writing or fixing Playwright tests under `e2e/`, running the suite, the e2e CI checks and the disposable e2e target |
| `project-frontend` | CSS in `assets/`, the Tailwind watcher, Stimulus controllers, Turbo patterns, icons, any frontend visual behaviour |
| `project-templates` | `.html.twig` files or Twig component PHP classes |
| `project-worktrees` | Git worktrees: provisioning, URLs, per-worktree databases, worktree tooling. Also the shared Docker stack, the dev app URL, Compose services, processes inside a container |
| `project-deploy` | Deploying to production, `terraform apply`, verifying the live version, the production container layout |
| `project-rotate-secrets` | Applying a pending secret rotation after `just rotate-secrets` collected it: `bin/rotate-secrets apply`, its exit codes, the re-encrypt check |
| `project-translations` | UI strings, translation keys, adding a new locale, the gamache translation check and its configuration |
| `loupe-design-rebuild` | Rebuilding the Design System artifact from the export: `app:design-system:export`, the pending-rebuild to-do |
| `project-site-review` | The site-review widget (`public/live-review/widget.js`), `src/Module/SiteReview/`, its API routes, dev harness or e2e specs |
| `loupe-documents` | Writing or revising any document submitted to the Loupe app through the `loupe` MCP |
| `project-tech-design` | A technical design that settles an architecture, an entity model, a module boundary, or a subsystem |
| `loupe-site-review` | Acting on site-review feedback through the `loupe` MCP: `feedback_list` or `card_get`, fixing feedback, `feedback_mark_addressed` |
| `loupe-board` | Working a project board through the `loupe` MCP: `card_create`, `card_list`, `card_get`, `card_update`, writing a card, linking a pull request |
| `loupe-discovery` | A discovery run of the project: `readiness_get`, `workflow_get`, `readiness_report_submit`, the readiness report |
| `loupe-inbox` | Asking the project owner through the `loupe` MCP: `inbox_search`, `inbox_join`, `inbox_ask`, `inbox_list`, `inbox_get`, `inbox_withdraw`, ending a turn after a blocking ask |
| `loupe-workers` | Reading worker runs and bridges, or stopping, resuming and cancelling workers through the `loupe` MCP: `worker_run_list`, `worker_run_get`, `bridge_list`, `worker_run_resume`, `worker_run_stop`, `card_hold`, `card_release`, `card_pause_release`, `bridge_command_cancel`, recovering workers after an outage |
| `loupe-analysis` | Running an analysis of a project's worker runs through the `loupe` MCP: `analysis_get`, `metric_query`, `analysis_report`, the cost report and its proposals |
| `loupe-product-design` | An interactive product design session with the owner through the `loupe` MCP, from a card or a one-line idea: `/loupe:product-design` |
| `loupe-tech-design` | An interactive tech design session with the owner through the `loupe` MCP: `/loupe:tech-design` |
| `loupe-stage-product-design` | One review round on a card's product document, or a prompt that names the skill |
| `loupe-stage-tech-design` | A card entering the Tech design column, or a prompt that names the skill: write or revise the tech design |
| `loupe-stage-implementation` | A card entering the Implementation column, or a prompt that names the skill: build the approved tech design into a ready pull request, or break an epic down into child cards |
| `loupe-stage-fix-round` | One round of review feedback on a card's current stage, a document review or a pull request review, from a fix request or run by hand |
| `loupe-stage-review` | A review work request for a card's pull request, or a prompt that names the skill: review the change at its head commit and call `agent_review_submit` |
| `loupe-stage-design-review` | A design-review work request for a draft product design or tech design, or a prompt that names the skill: review the draft and call `document_review_submit` |
| `loupe-stage-merge` | A card's pull request that is ready to merge or behind its base, or a prompt that names the skill |
| `loupe-stage-repair` | A repair work request after a rule's work failed and its retries ran out, or a prompt that names the skill |
| `symfony-authorization` | Generic Symfony authorization mechanics: Voter classes, attribute naming, `#[IsGranted]` placement, `subject:` resolution, `is_granted()` in Twig |
| `symfony-entity-route-mapping` | Routes that resolve entities from URL parameters: `{param:variable}` notation, `#[MapEntity]`, multi-entity routes |
| `project-comments` | Writing or reviewing code comments and docblocks anywhere in `src/`, `assets/` or `tests/` |
| `project-mutation-and-coverage` | Mutation testing, code coverage, fetching a weekly report artifact, or a test that asserts on elapsed time |
| `holding-a-merge-queue` | One session holds the merge queue, watches several pull requests, or verifies and merges branches that other sessions push |
| `loupe-recommendations` | Recommending, choosing or reviewing a solution: a design decision, a fix, a plan, a review finding, a patch against a correct fix |
| `working-with-prs` | Opening, gating, reviewing or merging a pull request |

A subagent does not inherit the skills you have loaded. When you delegate PHP, entity, migration or command work, state the relevant skill conventions in the prompt, or tell the subagent to invoke the skill first. A convention that lives only in a skill is silently missed otherwise. The brand-new-table migration rule in `project-backend` is the example that bit.

## Getting feedback on long documents

Submit a long-form document to the Loupe app through the `loupe` MCP, with `document_create` or `document_revise`, and give the user the review URL. This covers an implementation plan, a design spec, an RFC, an architecture write-up, or anything substantial the user reads at their own pace. That is what the app is for, so dogfood it. Invoke the `loupe-documents` skill before you write the document, because it carries the formatting rules for the review UI. Invoke `project-tech-design` as well when the document settles an architecture rather than a task list.

This applies only to a document meant for considered review. Keep ordinary conversation in the terminal: a clarifying question, a quick confirmation, a short summary, or options you are discussing inline. The test is simple. If the user would sit down and read it, send it to Loupe. If it is a turn in a discussion, keep it in the chat.

## Tracking open work

The project board in the Loupe app holds open work that has an addressee. Add a card when someone must do something later: a follow-up, a known issue, or a design decision to revisit. An observation asks nothing of anyone. Put an observation in the relevant skill or in `docs/` instead.

A problem in the code your branch touches is not open work. Fix it in that branch, even when the fix adds new content after an approval. Never add a follow-up card under an epic for it. A failing check is fixed in the branch wherever its cause lies. Any other problem outside your branch gets one Backlog card with no parent.

Work that the owner asks for in a session and that opens a pull request needs a card. A bridge worker already has one. A question, an investigation, or an operation with no pull request, such as a deploy, needs none.

1. Before your first edit, call `card_search`, then `card_create` with no `status`. The card lands in Backlog. Pass `reporter: human` when the owner asked for the work.
2. Open the draft pull request, and put the card URL in its body.
3. Call `card_update` with the pull request URL in `pullRequestUrls`, and pass no `status`.

The workflow then moves the card from Backlog to Implementation by itself, through its `pull-request-reopened` rule. Do not move the card yourself. The workflow refuses a manual move from Backlog to Implementation. A card that reached Implementation with no linked pull request would also start an implement worker. After step 3, the workflow reviews and merges the pull request.

Invoke the `loupe-board` skill before you write a card. It says how to read a board's columns with `board_columns`. It also carries the card types and the rule that `reporter` records who raised the card rather than who typed it. Never leave such a note in a code comment.

A card whose body opens with `**Parked.**` is paused. Do not start it, and do not move it out of its column, until the owner unparks it. The board has no parked state, so that line is the whole signal. A real parked state is worth having, and the board card 'Give the board a parked state' asks for one.

The board is as public as the instance that holds it. Write every card as public text, with no secrets, no customer names, and no complaints about people.

Finish a card by moving it to a terminal column, which is `done` on a board with the seeded columns. There is no delete tool, and only a person deletes a card from its own page.

## The bridge

The bridge is the `loupe bridge` process from `cli/`. It runs on the owner's machine, watches the board, and starts a Claude Code worker for each work request. The workflow rules in the app decide which work a card needs. The bridge decides how to run it.

The bridge rule file is local and not in git. On macOS it is `~/Library/Application Support/loupe/rules.yaml`. Run `loupe bridge reload` after you edit it, and `loupe update` to install a new CLI release.

`docs/extending/cli-bridge.md` describes the full format and the `loupe-workers` skill covers the runs.

## Git worktrees

Worktrees live in `.worktrees/`, which is gitignored. Every worktree is a full application of its own. Run `just worktree-up` and it gets its own URL at `https://<name>.loupe.dev.localhost`, its own migrated and seeded database, and its own compiled CSS. Log in with `dev@loupe.test` and `password`, or `admin@loupe.test` and `password` for the admin area.

A bridge worker starts in its card worktree, `.worktrees/card-<number>`, which the `before` command of its bridge work entry makes. The rule below covers the main session only.

**The main session never moves into a worktree.** If you are the session running in the main checkout, do not bind the session to a worktree and do not change its persistent working directory to `.worktrees/`. Only a session created to work in one enters it. Three things bite, all of them silently:

- Write access is single-valued per agent, and a plain subagent inherits the parent's current worktree. A main session that moves in binds every subagent it later dispatches to that same worktree. Work aimed at any other tree is then rejected, or lands in the wrong one.
- `main` is checked out in the main checkout and nowhere else. Merging, running `just cs` on `main` after a merge, and tearing a worktree down all need a session that still stands there. Git also refuses to check `main` out in a second worktree.
- Tearing down the worktree a session is bound to strands that session. Its writes keep pointing at a deleted path until it re-enters somewhere else.

Stay in the main checkout, and delegate the work that needs isolation.

**Running a command inside a worktree is not the same as moving into one.** The difference is whether the shell's working directory survives the call. `just worktree-up`, `bin/worktrees/compose-exec.sh` and the worktree e2e gate genuinely need the worktree as their cwd. Run them in a subshell, so the cwd dies with the command:

    ( cd .worktrees/<name> && just worktree-up )

A bare `cd .worktrees/<name> && just worktree-up` can leave a persistent shell in the worktree, so every later command also runs there. This rule was arrived at by accident.

- Provision by name from the main checkout with `just worktree-up NAME`. The no-argument form still works from inside a worktree, and needing it is what made sessions `cd` there in the first place.
- Always branch off `main`, or off the branch of the pull request you stack on. Never branch off whatever branch is current.
- Tear down with `just worktree-down <name>`, never with a bare `git worktree remove`.
- An editor or language server bound to the main checkout must not write worktree files. Confirm that every write-capable tool targets the worktree. Filesystem reads are safe from either checkout.
- For example, Serena edit tools stay bound to the main checkout and write there from a worktree. Serena read tools are safe from either checkout.
- Invoke the `project-deploy` skill before you deploy, run `terraform apply`, or report what version is live. It carries the trap that a `terraform apply` deployment does not re-pull the fixed `prod` tag, so the spec change ships and the code does not, and it names `/healthz` with `X-Probe-Token` as the only reliable way to read the running version.
- Invoke the `project-worktrees` skill before you provision, debug or write tooling for a worktree. It carries the commands, the symptoms and causes table (404 against 502, unstyled CSS, a rejected widget token), and the two rules that prevent real damage: never run bare `docker compose` from a worktree (a hook refuses it), and never match worktrees by directory name instead of slug.

## Harness integrations

Shared workflows belong in `.agents/skills/`. Put harness-specific adapters in
the harness's configuration directory, and keep them as thin wrappers around
the shared instructions. The `.claude/` links and settings provide Claude Code
compatibility; they are not the canonical source.

## Planning and shipping a feature

Decide two things at planning time, and say in the plan which of them the change needs.

**Documentation.** Ask whether the change alters what a user or an operator does, sees or configures. If it does, name the page that covers it under `docs/`: `docs/using/` for product behaviour, `docs/reference/` for commands and environment variables, `docs/operating/` for deployment and running, `docs/getting-started/` for a user's first steps, `docs/operating/install/` for installing an instance, and `docs/extending/` for the plugin and MCP surface. A feature that ships with no doc change claims that nothing observable changed, so make that claim deliberately.

**Landing page.** Ask whether the change adds, removes or alters a capability the landing page claims, or should now claim. The page is `templates/Module/Landing/landing.html.twig` with its partials in `templates/Module/Landing/landing/`, and the marketing footer is `templates/Module/Landing/landing/_footer.html.twig`. A landing page that describes a product one release behind is worse than one that says less.

The third check is the changelog entry, and it rides the branch that makes the change. It goes in its own fragment file, `changelog.d/<pull request number>.md`, never in `docs/CHANGELOG.md`. Two branches never write one fragment, so two entries never conflict. The documentation deploy folds the fragments on every push to `main`, so an entry reaches a reader with nobody doing anything. `just changelog` folds them into the committed file and deletes them, which is the release step. `working-with-prs` and `changelog.d/README.md` carry the format.

### What a new entity or feature must also register

Each row below fails silently. Nothing errors, no test goes red, and the miss surfaces later as wrong behaviour. Walk the table when a feature adds an entity, a route, an external call or a flag.

| Add an implementation of | When | If you forget |
|---|---|---|
| `Account/Deletion/AccountDataPurgerInterface` | the feature stores rows keyed to a user | account deletion leaves the data behind |
| `Account/Export/UserDataExporterInterface` | the same | the user's data export omits it |
| `Account/Install/InstallFlagDefaultsInterface` | the feature has a flag | a fresh install has no flag row |
| a `Project/Event/ProjectDeleting` listener | the module owns project-scoped rows | orphan rows outlive the project |
| `Project/Stats/ProjectStatsProviderInterface` | the entity is worth counting | project stats undercount |
| `Mcp/FlagGatedToolInterface` | an MCP tool is flag-gated | see below, it stays callable |
| `AdminMenuItemInterface` (admin bundle) | the feature adds an `/admin/` page | the page exists with no link to it |
| `DiagnosticInterface` (health-check bundle) | the feature calls an external service | a misconfiguration shows only as a user-facing failure |

Two of those carry an extra rule. A purger must read `$user->id` as a scalar, because the entity may be detached, and its `deletionOrder()` must run after `ProjectAccountPurger`. A flag-gated MCP tool must re-check its flag inside `__invoke`: the tag only filters `tools/list`, so a tool that skips the self-check is hidden and still fully callable.

Four more that no registry covers:

1. **A new flag does not reach an instance that is already installed.** `SeedFlagsController` seeds the flags at `/install`, and that page runs once. An instance that upgrades therefore holds no row for the new flag, so `FeatureFlagService::isEnabled()` falls back to the coded default and the feature ships invisible. There is no console command that seeds flags. Ship a per-flag migration instead, because `docker/prod/release.sh` runs migrations. `migrations/Version20260906142530.php` is the worked example. `bin/console app:dev:seed` seeds no flags either, so dev reads the same defaults.
2. **A new messenger transport or schedule name must reach the worker command in three files**, `docker/prod/worker-supervisord.conf` (which both `worker_command` in `terraform/main.tf` and the `worker` service in `docker/compose/prod.yaml` run), plus the dev `worker` service in `compose.yaml`. Otherwise the transport fills and nothing drains it. A message class missing from `config/packages/messenger.yaml` is handled inside the web request instead, which works and is in the wrong process.
3. **CSP is enforced in prod and empty in dev and test.** A feature that loads a new script, font, image or XHR origin works locally, passes e2e, and is blocked in production. Declare the origin in `config/packages/nelmio_security.yaml` with the `%env(default:app.default_csp_origin:VAR)%` shape, so an unset variable resolves to `'self'`.
4. **Navigation is hand-written in three places**: the app sidebar in `templates/base.html.twig`, the docs sidebar in `website/astro.config.mjs`, and the tool order in `Project/Mcp/AdvertisedTools`. A page that is not listed is reachable only by typing its URL. A tool that is not listed is still advertised, at the end of the list.

Security surfaces that fail loudly need no checklist entry. A new `/api/` route with no rule above `allow_if: 'false'` in `config/packages/security.yaml` is denied, and a missing `ApiTokenScope` case is a PHP error. Rate limiting is the exception: `config/packages/rate_limiter.yaml` is hand-declared, so a new token-authenticated write endpoint has none by default.

CI catches the rest. `DeploymentConfigParityCheck` cross-checks a new environment variable across `.env`, Terraform, the prod compose files and `docs/reference/environment.md`. A variable missing from that page fails `just gamache`, so `just ci` goes red.

## Pull requests

Invoke the `working-with-prs` skill before you open, gate or merge a pull request. It carries the full gate, the merge protocol, the ruleset facts and the wave rules. This is the irreducible summary, so that a session which skips the skill still does the right thing:

- The local gate is `just cs`, then `just phpstan`, `just arkitect` and `just gamache`. Then run PHPUnit on the tests for what changed, with `just phpunit tests/<path>` or `just phpunit --filter <name>`. Run `just js-test` when JavaScript changed, and `just cli-test` when `cli/` or `hooks/` changed. Run no Codex review. Open the pull request as a draft, and push. A review worker reviews it, and Loupe marks it ready when the review passes and the checks are green. CI's required checks, e2e included, are the full gate: read them on the pull request, and fix every failure, including pre-existing ones. A `PreToolUse` hook, `.agents/hooks/no-full-ci.sh`, refuses `just ci` and a full PHPUnit run.
- A branch whose every changed file ends in `.md` runs `just cs` and pushes, and says so in the body. Verify with `git diff --name-only origin/main...HEAD | grep -v '\.md$'`. Any output means the full gate applies.
- `main` is protected and takes `--squash` only. **Put the reasoning in the commit messages, not only in the PR body.** GitHub's squash default concatenates the branch's commit messages, so what survives in `git log` is what the commits said; the body survives only on the pull request page unless you pass `gh pr merge <n> --squash --body-file <file>`. A branch with substantive commit messages loses only the body's framing. A branch with thin ones loses everything. It requires no approving review. It requires a list of checks, and one of them is `Loupe / Approval`. Loupe posts that check on the head commit when the agent review passes, or when a person approves the head on GitHub. Read the list from the ruleset, because nothing in the repository fails when it goes stale. `working-with-prs` carries the command. An approval in chat is not an approval.
- Never approve your own work on GitHub. A PR with every required check green, `Loupe / Approval` included, is good to merge, without asking, when no workflow rule will merge it. A PR linked to a card belongs to the `merge-ready` rule of the workflow, so leave it to that rule. Hand-merge only a PR that no rule reaches. Never merge one that has a failing or pending check, or would need `--admin`. A hook refuses `gh pr merge --admin` and `gh pr ready`. A stacked pull request has no required checks, so never merge it while it targets its parent's branch.
- A green gate is not evidence the change is correct. Read the diff.
- Stack a branch on an unmerged pull request rather than waiting for it, and keep working until you are genuinely blocked. An unapproved pull request is not a blocker. A stacked pull request targets its parent's branch, never `main`: `gh pr create --base <parent-branch>`. Against `main`, its diff also shows every commit of the parent. `working-with-prs` carries what to watch for, which is a merge git reports as clean and is not, and what to do when the parent merges.

## Writing style

Write everything a human or another agent reads in ASD-STE100 Simplified Technical English, and remove the AI writing tells. This covers chat replies, commit messages, PR bodies, Loupe documents, board cards, and skills. It does not cover code, which follows the conventions below.

Apply the STE writing rules, not the approved word list. Technical names and technical verbs stay as they are.

- Use the active voice.
- Use a maximum of 20 words in an instruction, and 25 words in a descriptive sentence.
- Write one instruction per sentence.
- Use a maximum of six sentences in a paragraph.
- Use the simple present tense, or the imperative.
- Do not use a noun cluster of more than three words. An identifier is one word, however it is spelled.
- Keep articles and subjects. Full short sentences are the target. Telegraphic fragments are not.

Remove these tells: negative parallelism ("it is not X, it is Y"); a preamble that announces the answer before it gives it; stakes inflation; a summary of a section inside that section; a count before a list; bold lead-ins on bullets; em dashes and arrows; and magic adverbs such as "quietly", "deeply" and "seamlessly".

An existing file keeps its old prose until a rewrite touches it. The `compressing-skills` skill carries the full tell list and the procedure for a rewrite pass. `~/.agents/skills/ai-writing-tells/check.py` finds many tells mechanically, and its output is advice, not a gate.

## Recommendations and the quality bar

The owner ranks the priorities `correctness > simplicity > performance > shipping speed`. `docs/contributing/architectural-priorities.md` says which one yields in each of the six collisions, and when to escalate rather than apply the ranking. Read it before you call a trade-off. The records in `docs/adr/` apply that ranking to recurring questions. Read them before you design a new stage, a workflow rule or an automation.

Invoke the `loupe-recommendations` skill before you recommend, choose or review a solution. It says that effort alone never ranks a patch above a correct fix.

### The owner sets the bar, not the agent

Deciding what is good enough, and what has to be exactly right, is the owner's call. The failure to avoid is making that call silently and then presenting the outcome as a technical necessity, which removes the decision instead of informing it. Ranking findings as must-fix and blocking on them, declaring one branch's failing run to outrank a merge queue, imposing mutation checks as a standard, requiring a stale form submission to be refused rather than resolved: each of those is a judgement about how much rigour something deserves. Several of them may well be right. The point is who made them.

So name the severity and the cost of fixing, recommend, and let the owner set the bar. This matters most where the fix is expensive, where the defect is unreachable in practice, or where "leave it and note it" is a legitimate answer. Reserve blocking language for what is genuinely unsafe to ship. Say plainly when something is a judgement call rather than a requirement.

The tell to watch for is describing a preference as though it were a property of the code. "This must refuse" and "I think refusing is better, here is the cost of each" are different sentences, and only the second leaves the decision where it belongs.

### Weigh the trade-offs, do not defend one option

The habit to break is picking an approach and then arguing for it. The recommendation is rarely the problem. The problem is that the reasoning gets inflated to match the conclusion. A minor downside of the rejected option gets described in the register of a serious one, which makes the argument unfalsifiable and hides how close the call was. A measured one-millisecond cost does not need dressing up as a real correctness cost to justify the right answer. Overstating the case is worse than a weaker conclusion argued honestly.

What to do instead:

1. State the options and what each actually costs, in proportionate language.
2. Give a recommendation and the confidence behind it, without padding the rejected options to justify it.
3. Say plainly when a call is close, or when it rests on taste rather than evidence. "Either is fine, I lean X" is a legitimate answer.
4. Keep the alternatives genuinely available, rather than mentioning them as a courtesy before dismissing them.

This sits in tension with the instruction not to capitulate under pressure, and the tension is the point. Hold a position against disagreement, but do not manufacture support for it.

## General guidelines

- When you create new code, ask which module it belongs in. Ask your human if you are not sure.
- Generic code reused across modules can live in the project's root namespace.
- Stage files by name for a commit, never with `git add -A`. A hook refuses `git add -A`, `git add .` and `git commit -a`. Another feature branch may have uncommitted working-tree changes that `git add -A` silently includes.
- Read `git diff --cached` before you commit. Staging by name protects against another branch's *files*, not against another session's *hunks* in a file you are both editing, because `git add <path>` stages the whole working-tree version of that path. A commit that touched the one file you expected can still carry someone else's lines.
- Verify the current code state when you address PR feedback. Do not trust a prior agent's reply. A thread that says "Done" may not reflect what was committed, so read the file and confirm the change is present before you mark it addressed.
- Fix failing and flaky tests in any test run you observe, including ones that pass on retry, ones that pre-date your change, and ones unrelated to your task. A flaky test that passes on retry is a real failure that will break CI later. The only acceptable response to a flake is a fix.
- Frontend assets are committed; PHP dependencies are not. `assets/vendor/` (193 KB), `assets/icons/` (51 KB) and `assets/fonts/` (249 KB) are in git so a build and a self-hosted instance need no egress. `vendor/` is about 125 MB and stays ignored, restored by `composer install`. The asymmetry is a size judgement rather than a principle: about 490 KB total against roughly 250 times that. Committing an asset that grows past this scale is a new decision, not an application of this one.
- Outbound network calls are allowed, but only as opt-in features. A self-hosted instance must work with no egress out of the box, which is why `assets/icons/` is committed and `iconify.on_demand` is off in prod. That is a default, not a prohibition. A feature that calls out is fine when an operator has to switch it on, and when the app behaves correctly both while it is off and when the call fails. `about.update_check.enabled` is the reference shape: the flag is seeded off, the HTTP client is scoped with bounded timeouts, the answer is cached, and every failure path returns null rather than breaking the page. Do not argue against an egress feature on principle. Argue about whether it is opt-in and degrades cleanly. Client ID Metadata Document fetches are the one exception, and the owner approved them as always on. An MCP client such as Claude registers with Loupe only through such a document, because Loupe has no Dynamic Client Registration. A fetch runs only when a signed-in user connects an app, and a failed fetch refuses that app alone. `ClientMetadataFetcher` holds the SSRF guards.
- Never write a `TODO`, `FIXME` or `XXX` comment in code. Capture follow-up work in a card on the project board. An in-code TODO is invisible to future sessions and rots silently. `NoTodosCheck` enforces this under `just gamache`.
- Keep comments short. The default is no comment at all. A comment earns its place only by recording something a reader cannot recover from the code, the tests or `git log`. The reasoning behind a change belongs in the commit message or the PR body. `CommentBudgetCheck` fails on any run of 6 or more consecutive comment lines, in PHP, Twig, JS, CSS, YAML and the justfile alike. It is binding: `just gamache` exits non-zero, so `just ci` goes red. Shorten the block, or mark it `@comment-budget-ignore` when it has earned its length. Invoke the `project-comments` skill before you write a comment longer than two lines.
- Code comments must be self-contained. Never reference an internal or ephemeral development artifact a future reader cannot open: a numbered task (`Task 16`), a handoff or design document (`handoff screen 8`), a spec section (`§3.5`), a dev phase (`Part 1`), or a dated decision (`owner decision 2026-07-13`). State the underlying fact directly. `SelfContainedCommentsCheck` enforces this under `just gamache`.
- Give a Doctrine migration a real current-datetime timestamp in its `VersionYYYYMMDDHHMMSS` class name. Never use a round placeholder such as `…000000`. Two parallel branches that both use a round number collide on the version prefix. The class names still differ, so it is harmless, but it is confusing and it breaks `migrate-diff` ordering assumptions.
- To consume an unmerged `ubermuda/*` bundle branch, pin `"dev-<branch>#<full-40-char-sha> as dev-main"`. The `as dev-main` alias is load-bearing, because sibling ubermuda packages require `dev-main@dev`. After the bundle PR merges, repoint to plain `"dev-main#<merge-sha>"` and drop the alias in the same wave. Re-copy any assets the bundle ships, such as `feature_flag_form_controller.js`, at every pin change.
- A process started inside a container can only be stopped from inside it. A host-side `pkill` or the harness stop button kills only the wrapper, so use `docker compose exec php-fpm pkill -f <script>`. `project-worktrees` says more.
- Never run `bin/console tailwind:build` or `cache:clear` by hand in dev. The `tailwind` watcher rebuilds the CSS on its own. `project-frontend` says more.
- Never point the e2e suite at the dev host. It is destructive and wipes the dev database. `just e2e-up` makes the disposable target. `project-e2e` says more.
- PHP gotchas, including the Serena rename workaround, FormType rename side-effects and the constructor change checklist, live in `project-backend`.

## Common commands

```bash
just shell                    # Open bash in php-fpm container
just lint                     # Run parallel PHP linter
just cs-fix                   # Run PHP CS Fixer
just rector                   # Run Rector (PHP modernization)
just phpstan                  # Run static analysis (level 8)
just arkitect                 # Check module boundary rules (phparkitect)
just cs                       # Write-mode fixer pipeline: prettier, lint, rector, cs-fix, twig-cs-fix
just ci                       # Full check-only run for a person in a terminal; a hook refuses it for an agent: lint, cs-check (rector/cs-fixer/twig-cs-fixer dry-run), phpstan, arkitect, gamache, composer audit, PHPUnit, Vitest (e2e is separate)
just audit                    # Security advisories against composer.lock (also runs inside `just ci`)
just gamache                  # Run Gamache convention checker (replaces the seven custom check scripts)
just mutation                 # Infection over all of `src`; 15-25 min on a CI runner, far longer on a Mac
just mutation-diff            # Infection over the lines this branch changed; the one to run while developing
just phpunit-coverage tests/<path>  # PHPUnit coverage report at var/phpunit-coverage/html (separate from `e2e-coverage`); a hook refuses a bare run
just migrate-diff             # Generate migrations from entities
just migrate-run              # Run migrations
just js-test                  # Run Vitest over tests/js (needs Node alone)
just e2e tests/<area>/<x>.spec.ts  # Run ONE Playwright spec; a hook refuses the full suite
just ci-report e2e-coverage   # Fetch the e2e coverage report from a CI run
just open-coverage            # Open the merged HTML coverage report
just ci-report <report> [RUN] # Fetch a CI report: mutation, phpunit-coverage, e2e-coverage, e2e-timing, phpunit-timing
just browser-sync             # Live-reload proxy for template changes

just phpunit tests/<path>     # Run the PHPUnit tests under one path through ParaTest (PHPUNIT_PROCESSES, default 4); a hook refuses a full run for an agent
bin/console debug:router      # List all routes
```

To run a single test: `just phpunit --filter TestClassName`

To run a single JavaScript test: `just js-test tests/js/<name>.test.js`

To run a single e2e spec: `just e2e tests/<area>/<spec>.spec.ts`

## Architecture

The app is a Symfony application. Source code is organised into domain modules under `src/Module/`, and each module follows the same layout: `Controller/`, `Entity/`, `Form/`, `Repository/`, with templates mirrored under `templates/Module/`.

Every feature lives under `src/Module/`. Admin-facing controllers for a feature live under `Controller/Admin/` inside that feature's module. The `Admin` module is the shell for the admin area, covering layout, dashboard and auth promotion, and it does not own feature logic.

**Doctrine entity mapping.** `config/packages/doctrine.yaml` maps `dir: '%kernel.project_dir%/src'` with `prefix: 'App'`, which covers every entity under `src/Module/*/Entity/`. Do not narrow it to `src/Entity`, which is empty and exists only as a skeleton placeholder. When the mapping is wrong, PHPUnit fails with "Could not find the entity manager for class" on every entity.

**Stack.** Tailwind CSS v4 for UI, with no component library. The visual system is hand-rolled: CSS custom properties in the `@theme` design-token block, plus semantic component classes in `@layer components`, all in `assets/styles/app.css`. Templates use those semantic classes rather than raw utility strings. DaisyUI was removed in the 2026-06-19 visual redesign, so do not reach for `btn`, `card` or `modal` classes, and do not reintroduce a component library without a decision to. Symfony UX Icons (Lucide) and Stimulus.js provide interactivity. `project-frontend` carries the conventions.

**Core PHP conventions.** `project-backend` and `project-command-handler` carry the details and the exact patterns, so invoke them before you write PHP.

- Every controller extends the project-level `AppController`, never `AbstractController` directly. Use its `renderFormResponse()` for every form response, which sets 422 on an invalid submit.
- Any controller action that does more than render or redirect is backed by a command and handler pair, `Command/FooCommand.php` and `Command/FooHandler.php`, with no Messenger. `DomainErrors` carries field-level domain failures from the handler back to the form.
- Repositories are always constructor-injected. Never call `$em->getRepository()`.
- Properties are public by default in all PHP classes. Do not write a getter that merely exposes a property.
