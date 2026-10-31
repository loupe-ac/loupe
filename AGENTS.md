# AGENTS.md

This file guides any coding agent that works in this repository.

## Topic-specific guidance

These skills hold the detailed conventions for one area each. Invoke the relevant skill before you write or edit anything in that area. There are no exceptions. "I already know the pattern" and "this is a small change" are not exceptions, because the skills carry project-specific conventions that differ from the defaults and from each other.

A skill's name prefix says which side of the product you stand on. A `loupe-*` skill covers using the Loupe product through the `loupe` MCP, a `project-*` skill covers developing this codebase, and a `symfony-*` skill covers generic framework mechanics that any Symfony project shares. `project-site-review` and `loupe-site-review` show the split: one builds the widget, the other acts on the feedback the widget produces.

| Skill | Use when working on… |
|---|---|
| `project-backend` | PHP code under `src/`: forms, DTOs, entities, controllers, flash messages, the gamache layers and their configuration, email, CSRF. Also migrations, `composer.json` bundle pins and `doctrine.yaml` |
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

A subagent does not inherit the skills you have loaded. When you delegate PHP, entity, migration or command work, state the relevant skill conventions in the prompt, or tell the subagent to invoke the skill first. A convention that lives only in a skill is silently missed otherwise.

## Getting feedback on long documents

Submit a document that the user reads at their own pace to the Loupe app with `document_create` or `document_revise`. Examples are a plan, a spec or an RFC. Give the user the review URL. Invoke `loupe-documents` before you write it, and `project-tech-design` as well when it settles an architecture. Keep a turn in a discussion in the chat.

## Tracking open work

The project board in the Loupe app holds open work that has an addressee. Invoke the `loupe-board` skill before you write a card. It says what earns a card, where an observation goes instead, and that the board is public text.

Fix a problem in the code your branch touches in that branch, even when the fix adds new content after an approval. Never card it under an epic. Fix a failing check in the branch, wherever its cause lies.

Work that the owner asks for in a session and that opens a pull request needs a card. A bridge worker already has one. A question, an investigation, or an operation with no pull request, such as a deploy, needs none.

1. Before your first edit, call `card_search`, then `card_create` with no `status`. The card lands in Backlog. Pass `reporter: human` when the owner asked for the work.
2. Open the draft pull request, and put the card URL in its body.
3. Call `card_update` with the pull request URL in `pullRequestUrls`, and pass no `status`.

The workflow then moves the card to Implementation, and reviews and merges the pull request. Do not move the card yourself.

A card whose body opens with `**Parked.**` is paused: do not start it or move it.

## The bridge

The bridge is the `loupe bridge` process from `cli/`. It runs on the owner's machine, watches the board, and starts a Claude Code worker for each work request. The workflow rules in the app decide which work a card needs. The bridge decides how to run it.

`docs/extending/cli-bridge.md` describes its local rule file, `rules.yaml`. A running bridge reads a change only after `loupe bridge reload`. `project-deploy` covers a bridge update, and `loupe-workers` covers the runs.

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

A bare `cd .worktrees/<name> && just worktree-up` can leave a persistent shell in the worktree, so every later command also runs there.

- Always branch off `main`, or off the branch of the pull request you stack on. Never branch off whatever branch is current.
- Invoke the `project-deploy` skill before you deploy, run `terraform apply`, or report what version is live. It carries the trap that a `terraform apply` deployment does not re-pull the fixed `prod` tag, so the spec change ships and the code does not, and it names `/healthz` with `X-Probe-Token` as the only reliable way to read the running version.
- Invoke the `project-worktrees` skill before you provision, debug, tear down or write tooling for a worktree, or stop a process inside a container. Never run bare `docker compose` from a worktree.

## Planning and shipping a feature

Decide two things at planning time, and say in the plan which of them the change needs.

**Documentation.** Ask whether the change alters what a user or an operator does, sees or configures. If it does, name the page that covers it under `docs/`: `docs/using/` for product behaviour, `docs/reference/` for commands and environment variables, `docs/operating/` for deployment and running, `docs/getting-started/` for a user's first steps, `docs/operating/install/` for installing an instance, and `docs/extending/` for the plugin and MCP surface. A feature that ships with no doc change claims that nothing observable changed, so make that claim deliberately.

**Landing page.** Ask whether the change adds, removes or alters a capability the landing page claims, or should now claim. The page is `templates/Module/Landing/landing.html.twig` with its partials in `templates/Module/Landing/landing/`, and the marketing footer is `templates/Module/Landing/landing/_footer.html.twig`. A landing page that describes a product one release behind is worse than one that says less.

The third check is the changelog entry. Write it on the same branch, in `changelog.d/<pull request number>.md`, and never in `docs/CHANGELOG.md`. `working-with-prs` and `changelog.d/README.md` carry the format.

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

- The local gate is `just cs`, then `just phpstan`, `just arkitect` and `just gamache`. Then run PHPUnit on the tests for what changed, with `just phpunit tests/<path>` or `just phpunit --filter <name>`. Run `just js-test` when JavaScript changed, and `just cli-test` when `cli/` or `hooks/` changed. Run no Codex review. Open the pull request as a draft, and push. A review worker reviews it, and Loupe marks it ready when the review passes and the checks are green. CI's required checks, e2e included, are the full gate: read them on the pull request, and fix every failure, including pre-existing ones. Never run `just ci` or a full PHPUnit run.
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
- Stage files by name, never with `git add -A`, `git add .` or `git commit -a`.
- Read `git diff --cached` before you commit. Staging by name protects against another branch's *files*, not against another session's *hunks* in a file you are both editing, because `git add <path>` stages the whole working-tree version of that path. A commit that touched the one file you expected can still carry someone else's lines.
- Verify the current code state when you address PR feedback. Do not trust a prior agent's reply. A thread that says "Done" may not reflect what was committed, so read the file and confirm the change is present before you mark it addressed.
- Fix failing and flaky tests in any test run you observe, including ones that pass on retry, ones that pre-date your change, and ones unrelated to your task. A flaky test that passes on retry is a real failure that will break CI later. The only acceptable response to a flake is a fix.
- `assets/vendor/`, `assets/icons/` and `assets/fonts/` are in git, so a build and a self-hosted instance need no egress. `vendor/` stays ignored, because it is about 250 times larger. A committed asset of a much larger size needs a new decision.
- Outbound network calls are allowed, and a feature needs no opt-in switch to make one. A self-hosted instance must work with no egress, so a failed or timed-out call never breaks the app, and the app behaves correctly while the call fails. This is why `assets/icons/` is committed and `iconify.on_demand` is off in prod. `about.update_check.enabled` is the reference shape for a call that degrades cleanly: the HTTP client is scoped with bounded timeouts, the answer is cached, and every failure path returns null rather than breaking the page. Do not argue against an egress feature on principle. Argue about whether it degrades cleanly. An MCP client such as Claude registers with Loupe only through a Client ID Metadata Document, because Loupe has no Dynamic Client Registration. A fetch runs only when a signed-in user connects an app, and a failed fetch refuses that app alone. `ClientMetadataFetcher` holds the SSRF guards.
- Never write a `TODO`, `FIXME` or `XXX` comment in any file. Put follow-up work in a card on the board. `NoTodosCheck` reads only PHP.
- Keep comments short, and write none by default. Put the reasoning behind a change in the commit message. Invoke the `project-comments` skill before you write a comment longer than two lines.
- Keep code comments self-contained. Never cite a task number, a design document, a spec section, a dev phase or a dated decision. `SelfContainedCommentsCheck` reads only PHP and Twig, so apply the rule to `assets/` yourself.
- Never run `bin/console tailwind:build` or `cache:clear` by hand in dev. The `tailwind` watcher rebuilds the CSS on its own. `project-frontend` says more.
- Never point the e2e suite at the dev host. It is destructive and wipes the dev database. `just e2e-up` makes the disposable target. `project-e2e` says more.
- PHP gotchas, including the Serena rename workaround, FormType rename side-effects and the constructor change checklist, live in `project-backend`.

## Common commands

```bash
just shell                    # Open bash in php-fpm container
just cs                       # Write-mode fixer pipeline: prettier, lint, rector, cs-fix, twig-cs-fix
just phpstan                  # Run static analysis (level 8)
just arkitect                 # Check module boundary rules (phparkitect)
just gamache                  # Run Gamache convention checker
just phpunit tests/<path>     # Run the PHPUnit tests under one path; a hook refuses a full run for an agent
just phpunit --filter <name>  # Run one PHPUnit test
just js-test                  # Run Vitest over tests/js, or one file with tests/js/<name>.test.js
just cli-test                 # Run the Go CLI and hook tests
just e2e tests/<area>/<x>.spec.ts  # Run ONE Playwright spec; a hook refuses the full suite
just migrate-diff             # Generate migrations from entities
just migrate-run              # Run migrations
just mutation-diff            # Infection over the lines this branch changed
just ci-report <report> [RUN] # Fetch a CI report: mutation, phpunit-coverage, e2e-coverage, e2e-timing, phpunit-timing
just ci                       # Full check-only run for a person in a terminal; never for an agent
```

`just --list` shows every recipe.

## Architecture

The app is a Symfony application. Source code is organised into domain modules under `src/Module/`, and each module follows the same layout: `Controller/`, `Entity/`, `Form/`, `Repository/`, with templates mirrored under `templates/Module/`.

Every feature lives under `src/Module/`. Admin-facing controllers for a feature live under `Controller/Admin/` inside that feature's module. The `Admin` module is the shell for the admin area, covering layout, dashboard and auth promotion, and it does not own feature logic.

**Stack.** Tailwind CSS v4 with no component library, Symfony UX Icons (Lucide) and Stimulus.js. Templates use the semantic classes and design tokens in `assets/styles/app.css`. DaisyUI is gone, so do not reach for `btn`, `card` or `modal` classes. Do not add a component library without a decision. `project-frontend` carries the conventions.

**Core PHP conventions.** `project-backend` and `project-command-handler` carry them, such as extending `AppController` and never `AbstractController`, `renderFormResponse()`, the command and handler pair, `DomainErrors` and injected repositories. Invoke them before you write PHP. Properties are public by default in all PHP classes, so do not write a getter that only exposes a property.
