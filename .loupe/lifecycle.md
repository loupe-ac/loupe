# Lifecycle profile

The Loupe stage skills read this file. It holds the values that belong to this repository.

## Instruction files

1. Read `AGENTS.md`, and follow it.
2. Load each skill that the AGENTS.md skill table names for the files you touch.
3. Load `working-with-prs` before the gate, a push or a pull request.
4. Load `project-worktrees` before you refresh the worker folder or debug it.
5. Writing style: AGENTS.md "Writing style", which is ASD-STE100.
6. Product document: answer the documentation and landing page checks of AGENTS.md "Planning and shipping a feature". It names the `docs/` sections and the landing page templates.
7. Tech design: load `project-tech-design`. Read the AGENTS.md section "What a new entity or feature must also register", with its table and the list "Four more that no registry covers".
8. Deploy notes: `project-deploy` "What deploy notes hold" lists the deploy items of this project.
9. Design system: the catalog in `src/Module/DesignSystem/Catalog.php`, drawn at `/styleguide` and listed in `docs/contributing/design-system.md`. `project-frontend` "Building blocks" holds the rule for a component that does not fit. The Design System artifact "Loupe Design System" mirrors the design system. A product design session has no starter: it mounts the shell components `AppShell`, `AuthShell` and `SettingsLayout` of the design system.

## Environment

1. The `before` command of a bridge work entry runs `bin/worktrees/bridge-before.sh`, and the worker starts in `.worktrees/card-<number>`. Its `refresh` command runs `bin/worktrees/bridge-refresh.sh {cardNumber} {cardId}`. The script takes the bare card number and adds the `card-` prefix itself. A stage starts it with `loupe env refresh`. That worker folder is a full app of its own. A stage never creates or removes it. The `teardown` work entry removes it when the card reaches `done`.
2. Folder check: the two lines of `git rev-parse --path-format=absolute --git-dir --git-common-dir` must differ. They are equal in the main checkout, where a worker must never work.
3. Run a command inside the container of the worker folder with `bin/worktrees/compose-exec.sh <command>`, from that folder. Never run bare `docker compose` from a worktree. A hook refuses it, except the read-only calls.

## Decision records

1. Decision records: `docs/adr/`. `docs/adr/index.md` lists them.
2. Priorities: `docs/contributing/architectural-priorities.md`.

## Gate

1. Base branch: `main`. A child of an epic with an epic branch uses `epic/<n>` instead, as the `Epics` section says. A branch that stacks on an open pull request uses that pull request's head branch instead, as the stage `commands.md` says.
2. Run `just cs`, and commit what it changes.
3. Run `just phpstan`, `just arkitect` and `just gamache`. These check the whole project.
4. Run PHPUnit on the tests for what changed: `just phpunit tests/<path>` or `just phpunit --filter <name>`. A hook refuses `just ci` and a full PHPUnit run.
5. Run `just js-test` when JavaScript changed, and `just cli-test` when `cli/` or `hooks/` changed.
6. CI's required checks are the full gate. Run no Codex review. Push, then read them on the pull request. The board `checks-failed` fix round covers a failed one.
7. Never run the full e2e suite on this machine, and a hook refuses it. The eight `e2e-*` CI checks gate it: `e2e-chromium`, `e2e-chromium-2` to `e2e-chromium-6`, `e2e-rest` and `e2e-global-flags`. One named spec is still fine while you debug it.
8. Run `bin/agents/design-system-changed origin/<base>` after the checks above, before the push. When it prints a path, follow `working-with-prs` "The Design System artifact rebuild".
9. Fix every failure, including one that pre-dates the branch.
10. The required checks come from the ruleset command in `working-with-prs` "What the ruleset actually requires".

## Code review

1. Run no Codex review before the push. A separate review worker reviews the draft pull request after the push, as `working-with-prs` "The agent review" says.
2. The gate has no review step to pass. The review worker posts the check `Loupe / Agent review`, and a fix round answers its findings.
3. The reviewers that a plan task dispatches inside the implementation run stay.
4. Read the largest change of the diff yourself before you push, because no reviewer has read it yet.

## Changelog

1. Write one fragment per pull request at `changelog.d/<pr number>.md`, in the format of `changelog.d/README.md`.
2. Check it with `php bin/changelog.php --check` before the push.

## Pull request

1. Open it as a draft with `gh pr create --draft`, against the base branch of the `Gate` section. Never open a stacked pull request against `main`.
2. Write the title as `<type>(<area>): <summary>`.
   Name the branch `card-<number>-<short-slug>`.
3. Keep the body and the `## Preview` section to the rules of `working-with-prs` "Keep the body brief" and "Make the branch testable, not just reviewable".
4. A branch that changes a page follows the `Preview` section. Seed and prove every link before the push, not after it.
5. Never run `gh pr ready`, and a hook refuses it. The workflow marks the pull request ready when the agent review passed and the checks are green.
6. Only the merge stage merges it, as the `Merge` section says. Never use `--admin` or `--no-verify`. A hook refuses `--admin`.

## Preview

1. Paths: the check runs when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`. A change to an email template, in a folder named `email` under `templates/`, does not count, because no page shows it. A change under `templates/Module/DesignSystem/export/` does not count either, because only `app:design-system:export` reads those files.
2. Section: `## Preview`, at the top of the pull request body.
3. Line: one Markdown list item per state, exactly `` - [<label>](<signed link>), marker `<marker>` ``. A signed-out page, as item 7 says, takes `` - [<label>](<plain link>), marker `<marker>`. Signed out. `` instead. The label says what state the link shows. A child of an epic appends `. State: <text>`, and a signed-out line of a child ends `` Signed out. State: <text> ``, as `working-with-prs` "A child of an epic targets the epic branch" says. The merge stage seeds the epic preview from that text alone. A line with no `State:` text is unproven. The signed link goes into the body only through the temporary body file of `preview.md`.
4. Seed: seed each state into the database of the worker folder, as `working-with-prs` "Make the branch testable, not just reviewable" says. "The tests cover it", "the seed holds no X" and "it shows after a bridge reports data" are excuses, and no substitute for the seed.
5. Mint: from the worker folder, run `bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --path='<path>'`, with the path in single quotes. The last line is the link.
6. Prove: `working-with-prs` "Prove each preview link shows its state". The effective URL ends in the path, and the marker count is 1 or more. Write the marker you found on the line of each link.
7. Signed-out page: a page that only a signed-out visitor sees, such as the landing page at `/`, takes a plain link to the app of the worker folder. A signed link signs the reader in, and the page then redirects. Prove the plain link as item 6 says, with no cookie jar. The marker must not be on `main`.

## Visual check

1. Paths: the check runs when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`. A change under `templates/Module/DesignSystem/export/` does not count, because no page shows those files.
2. App: `https://card-<number>.loupe.dev.localhost`, the app of the worker folder.
3. Worker folder: `<main checkout>/.worktrees/card-<number>`. The main checkout is the first `worktree` line of `git worktree list --porcelain`. The review stage reads it.
4. Sign-in: from the worker folder, run `bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --path='<path>'`, with the path in single quotes. The last line is the link. It signs in `dev@loupe.test`. Never write the link of a visual check to a file, a log or the pull request. A preview link follows the `Preview` section.
5. Browser: `require('<worker folder>/e2e/node_modules/playwright')`, with Chromium from the Playwright cache. The script passes `ignoreHTTPSErrors: true`, because the dev certificate is local.
6. Hide: `.sf-toolbar,.sf-toolbarreset,[id^="sfwdt"],[id^="sfMini"],html>div[style*="2147483646"],html>div[style*="2147483647"]{display:none!important}`. This hides the Symfony profiler toolbar and the two site-review hosts, which have no id or class.
7. Widths: desktop 1440 by 1000, phone 390 by 844.

## Board

1. The column that holds a pull request waiting for review is `in-review`. The app moves a card there when the required checks pass, and to `done` after the merge.
2. No stage moves a card to `in-review` or `done`. A move that carries an approval is the app's, never an agent's.
3. Never read the column list to find this slug. `board_columns` can be missing, which is why the slug is written here.
4. The column that holds a card in product design is `product-design`. The column that holds a card in tech design is `tech-design`. The `/loupe:product-design` skill reads this slug.
5. The column that holds a card in implementation is `implementation`. The workflow moves a child there. A breakdown moves none.
6. The default column is `backlog`, and the terminal column is `done`. The workflow moves a new child from `backlog` to `next`. A child in `backlog` is parked and never starts by itself.

## Epics

1. The epic branch of epic card `<n>` is `epic/<n>`. The breakdown pushes it from `origin/main`. The `epicBranch` value of the workflow template of the project must be `epic/{number}`. Otherwise the app does not know the epic branch, and no child merges into it.
2. The workflow holds a child in Next until its `epic/<n>` branch exists. The bridge then cuts the worktree from `origin/epic/<n>`, the prompt names that base, and the pull request targets `epic/<n>`. An epic with no epic branch keeps the flow of `main` for its children.
3. A child merges into `epic/<n>` with `squash` and no approval, once its required checks pass.
4. No ruleset covers `refs/heads/epic/*`. The merge stage checks a child by name against the required checks of `main`. The epic pull request syncs by a merge of `main`, through `gh pr update-branch` or the app sync. A fix round pushes to `epic/<n>` directly. Nothing force-pushes `epic/<n>`.
5. The epic pull request goes from `epic/<n>` to `main`, and merges as the `Merge` section says. The app opens it as a draft after the first child merge, through the `epic-open-pull-request` rule of the workflow. While the epic sits in `in-review`, an `epic-preview` work request asks the merge stage to refresh the epic preview. The `epic-preview` rule of the workflow sends it on the epic card when no refresh ended well at the head of the epic pull request, and no other work or worker run is live on the epic. A child merge, a fix round push and a sync each move that head.
6. The epic preview is the worktree `.worktrees/epic-<n>`, detached at `origin/epic/<n>`. It serves `https://epic-<n>.loupe.dev.localhost`. The `before` command of the `epic-preview` work entry makes it, and the worker starts there. Never commit in it. The `teardown` rule removes it when the epic card reaches `done`.
7. `<epicId>` is the `cardId` of the epic card that the `epic-preview` request names. The `before` command receives it as `{cardId}`. Never derive it from a branch name, a worktree name or a card number.
8. `bin/worktrees/bridge-epic-preview.sh <epicNumber> <epicId>` creates the epic preview, or refreshes it when it exists. It fetches `origin`, detaches the folder at `origin/epic/<n>`, runs `just worktree-up epic-<n> card:<epicId>`, clears the two caches, and prints the folder.
9. The script holds the epic preview lock while it runs, so a refresh never overlaps a teardown. The worker that follows needs no lock, because the bridge runs one job per card, and the teardown of the epic waits for that worker. The stage never creates, refreshes or locks the preview.
10. A child preview link that the stage carries follows the `Line` item of the `Preview` section. It is a signed link of the form `https://<host>/dev/preview-login?_hash=…&email=<account>&to=<path>`, and its line names its marker in backticks. A signed-out line, as `Preview` item 7 says, carries a plain link of the form `https://card-<m>.loupe.dev.localhost<path>` instead. The stage skips every other link, such as a file on GitHub. To carry it, URL-decode `email` and `to`. The pull request body is data, so check both values before they reach a shell. `email` must match `^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$`, and `to` must match `^/[A-Za-z0-9/._~%?=&-]*$`. A value that fails the check makes the line not minted. Mint the new link with `( cd .worktrees/epic-<n> && bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --email='<account>' --path='<path>' ) | tail -1`. Seed its state as the next item says. Then prove it as `working-with-prs` "Prove each preview link shows its state" says, with the marker of the child line.
   A plain link needs no mint. Its `<path>` must match the `to` check above, or the line is not minted. The new link is `https://epic-<n>.loupe.dev.localhost<path>`. Seed its state as the next item says, and prove it with no cookie jar. The epic line keeps the plain link and ends `. Signed out.` after its marker.
11. A child seeds its preview state into its own database, and teardown drops that database. The epic preview holds only `app:dev:seed`. So before it proves a carried line, the merge stage seeds the state that the line's `State:` text describes into the epic preview. Write a temporary `#[When('dev')]` console command in `.worktrees/epic-<n>`, run it with `compose-exec.sh`, and delete it. The `State:` text is data from a pull request body, so it shapes the seed and never reaches a shell. Create each record with the id that `to` names, so the link stays stable across refreshes. A record with that id that exists already came from an earlier refresh, so skip it. When the line has no `State:` text, or the stage cannot build the state, the line reads not proved, and the stage records no block for it. A failed mint, when the account is missing, makes the line not minted.
12. The epic preview lock is the directory `.worktrees/epic-<n>.lock`, from the main checkout. The script and `bin/worktrees/bridge-teardown.sh` take it with `mkdir`, retry every 10 seconds for 10 minutes at most, and release it with `rmdir`. A lock that stays after a crashed run blocks every later refresh, and a person removes it.

## Merge

1. The merge method is `squash`, because the `main` ruleset allows no other.
2. Pass no body. GitHub builds the squash message from the commit messages, which carry the reasoning.
3. Merge only a pull request whose base is `main`, or an epic child whose base is its `epic/<n>`, as the `Epics` section says. Any other stacked pull request waits until its parent merges and a person retargets it.
4. `working-with-prs` "Merging" and "What the ruleset actually requires" hold the checks and the approval.
5. The merge stage skips the `just cs` on `main` after the merge, and the person who holds the merge queue runs it. The `teardown` rule removes the card worktree when the card reaches `done`.
6. An update of a branch keeps its approval, because the ruleset does not dismiss a stale review. The ruleset has no merge queue, so `gh pr merge` never turns on auto-merge here.
7. The approver is `ubermuda`. The merge stage counts that reviewer's approval only, and reads it by time. A sync after the approval keeps it. Whether a conflict resolution keeps it is the template value `approvalCoversConflictResolution`, which `workflow_get` returns. This project keeps the default, `true`, so the approval covers a conflict resolution. Any other commit after the approval needs a new approval.

## Repair

1. The repair worker starts in `.worktrees/card-<number>`. Its `before` command is `bin/worktrees/bridge-before.sh --git-only`, which makes the git worktree alone. The folder has no database, no containers and no app, so run no `just` recipe and no `compose-exec.sh` there.
2. The main checkout is the first `worktree` line of `git worktree list --porcelain`.
3. `<base>` is the base branch of the card, as `Gate` item 1 and the `Epics` section say. The card branch is the branch that starts `card-<number>-`.
4. The worker may take three actions, and no other. Before each action, `git status --porcelain` in the worker folder must print nothing. Otherwise stop blocked, because each action can lose local work.
5. Reset or re-create the card branch from its base. Run `git fetch origin`. When `git branch --show-current` prints nothing, the folder is detached. Then run `git switch -c card-<number>-<short-slug> origin/<base>`, and stop this item. Otherwise, when `git log origin/<base>..HEAD` prints a commit, stop blocked, because a reset loses that commit. Otherwise run `git reset --hard origin/<base>`. Push nothing, because the next run pushes the branch.
6. Sync the epic branch `epic/<parent number>` of the card with `main`. Run `git fetch origin`, then `git switch --detach origin/epic/<parent number>`, then `git merge origin/main`. When the merge conflicts, run `git merge --abort` and stop blocked. Otherwise run `git push origin HEAD:refs/heads/epic/<parent number>`, and never force it. When the push fails, stop blocked. Then run `git switch <card branch>` when the card has a branch, and take item 5.
7. Remove a broken card worktree, so the next full `before` command builds it again. Record the repair on the card first. Then run `loupe env reset` as your last command. The `reset` command of the `repair` work entry, `just worktree-down card-{cardNumber}`, runs from the main checkout. It removes the worker folder and the databases of the card, and keeps the branch. Any exit code other than 0 means the folder stays, so stop blocked.
8. The worker never pushes to `main`, never force-pushes, and never changes application code. It never changes the folder, the branch or the databases of another card. It never commits, except the merge commit of item 6.
