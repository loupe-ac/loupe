---
name: loupe-discovery
description: "Use when a bridge runs a discovery run of a Loupe project, when a prompt names loupe-discovery, or when writing a readiness report."
---

# Running a Loupe discovery

Discovery finds what Loupe works better with before agents work the cards of a project. A check that the project does not meet is a suggestion, never a failure. You read the project setup, the workflow and the repository, and you write a readiness report. The owner ticks proposals in the report. When the owner approves the report, Loupe creates one card in Next for each ticked proposal.

The prompt names the project, the `projectId`, the card number and the `cardId` of the discovery card.

## Read only

Discovery changes nothing. Every change goes into the report as a proposal. Do not commit, push or create a branch. Do not change a file in the repository. Do not create, change or move a card. Do not change the project, its columns, its automation, its inbox or its origins.

These tools are forbidden: `card_create`, `card_update`, `column_create`, `column_update`, `column_reorder`, `column_delete`, `automation_settings_update`, `project_update`, `project_origins_set`, `readiness_guide_set` and `discovery_start`.

You can run commands that only read, such as `git remote -v`, `ls` or `cat`. Do not run a build or a test suite that writes outside a temporary directory. Read how the project runs them instead.

## Steps

1. Call `readiness_get`. Each of its `rows` has a `key`, `done` and `status`. The keys are `agent`, `workflow`, `bridge`, `github`, `agent_account` and `repository`. These rows tell you what Loupe sees of the setup.
2. Read the `discovery` field of `readiness_get`. It is the latest run of the project. Its `cardNumber` must be the card number of your prompt. If it is not, stop and report the mismatch.
3. Keep `discovery.runId`. It is the `runId` that `readiness_report_submit` takes. When `discovery.state` is `revising`, follow "Revising a report" below. When it is neither `requested` nor `revising`, the run takes no report. Stop and report the state.
4. Call `workflow_get`. It returns the `template` with its `key` and `version`, and the `columns`. Its `kinds` list each kind of work that the workflow asks a bridge to do. Each kind has an `origin` of `template` or `app`, the `rules` that ask for it, and its `checks`. The checks name what that work needs.
5. Read the repository. Find the build and test commands, the CI config and the agent instruction files, such as `AGENTS.md` or `CLAUDE.md`. Read `.loupe/lifecycle.md` and its sections when the file exists. Read the git remote.
6. Apply the generic checks.
   - How to build and test the project, and whether CI runs those commands.
   - An instructions file for agents, such as `AGENTS.md` or `CLAUDE.md`.
   - The GitHub remote, the GitHub App, the bridge and the agent GitHub account. Use the `readiness_get` rows for what Loupe sees.
   - The browser tool of the visual check is installed, and an agent can use it. The stage skills use it for the visual check of `loupe-stage-implementation/references/visual-check.md`. Check neither the profile section nor the app.
     - The tool is the one that the profile `Visual check` section names, or Playwright when the section is missing or names none. Find it in the repository and its installed copy, such as a `package.json` for Playwright.
     - From a new temporary directory, launch it headless. Take one screenshot of a static HTML file, and read the image.
     - A missing tool is a suggested finding. Propose a card that installs the tool, or that makes its browser start.
   - The repository profile, `.loupe/lifecycle.md`. Every stage reads it.
     - When the file is missing, or git does not track it, the check is a suggested finding. The owner then runs `loupe init` and commits the file.
     - Otherwise search it for the text "detected, check it". `loupe init` writes that text after each value it guessed. Compare each marked value with the repository, the CI config, the agent instruction files and `workflow_get`.
     - Also search it for the text "owner to fill in". `loupe init` writes that text where a stage needs a value that it cannot know. Each such line is a suggested finding with no card (step 10). The evidence names the section and the line, and asks the owner to write the value and commit it.
     - A gate command that does not exist, or that CI does not run, is a wrong value. A check that CI runs before a merge and the gate lacks is a missing value, so propose it too.
     - Write one proposal for each wrong value. The body names the section, the line, the value and the correct value. The proposal removes the marker from the line it fixes.
     - A marked value that is right also loses its marker, so list it in the evidence and propose no card.
   - Each setup step that an agent cannot do through the MCP. For example, only the owner can install the GitHub App in a browser, and no tool changes the workflow template.
7. Apply each check of each kind against the repository. A kind can have an empty `checks` list. Write no finding for such a kind. Name it in the `summary` as a kind that only the generic checks cover.
   - Some checks start with a condition, such as "When the project has a user interface". Such a check gets no finding when the project does not meet the condition. Name it in the `summary` as skipped, with the reason.
   - A check for a Design System artifact that mirrors the design system looks for three marks. Look in `.loupe/lifecycle.md`, the agent instruction files and the skills they name.
     - M1 is the title or the address (`claude.ai/artifact/<id>`) of the Design System artifact. The phrase "Design System" alone does not pass.
     - M2 is a command or a script that builds the artifact files from the code. Check that an M2 command or script exists, and never run it.
     - M3 is the steps that say when and how to rebuild the artifact.
     - M1 always applies. M2 and M3 apply only when the design system also lives in the repository. A project that keeps its design system only in a named artifact passes the design system check and needs M1 alone.
     - A missing mark is a suggested finding, and the evidence names it. A passing mark names the file and the line.
   - When the worker has the `Artifact` tool, call it once with `list` and `type` "Design System", and confirm that an artifact matches M1. When none matches, the finding is suggested. A headless worker has no `Artifact` tool. Without it, trust the repository, and say in the evidence that the artifact was not checked. Never list other artifacts of the account, and call no action that writes.
   - A check that starts `Conflict check:` compares the way the repository already works with the workflow. Follow "Conflict check" below.
   - A project with a suggestion on the design system itself proposes a card to extract one. A suggestion on the artifact proposes a card for the marks an agent can add. A project that keeps its design system in the repository and has no artifact gets a suggested finding, with no card. The evidence says that the owner creates the artifact in a browser on claude.ai, from the repository export. The owner then names it in `.loupe/lifecycle.md` or the agent instructions (step 10).
8. Write one proposal for each suggestion that a card can close. Give it a `key` that is unique and kebab-case, a `title`, a `type` and a `body`.
   - Call `board_columns` to read the card types of the project. The `type` is the `key` of a type whose `children` is false.
   - Write the body so that a session with no context can act on it. Say what to do, why, and where.
9. Call `card_search` with the key words of each suggestion before you write its proposal. `card_search` returns finished cards too, so read each row's `status`. When an open card covers the suggestion, set `openCardNumber` to its number.
10. Some suggestions need the owner on a settings page or in a browser. Do not propose a card for such a suggestion. Say what the owner must do in the evidence of the finding.
11. Call `readiness_report_submit` with `runId`, `workflow`, `findings`, `proposals` and `summary`. Set `workflow` to the template `key`. Each finding has a `check`, a `status` of `ready` or `suggested`, and an `evidence` in short Markdown. Keep the `summary` to a few sentences. The tool returns the `documentId` and the `url` of the report.
12. Open a review of the report in the inbox. Call `inbox_ask` with one item of `kind` `review`, `reviewDocumentId` set to the `documentId`, and `cardIds` set to the `cardId`.
    - Pass `sessionId` and `bridgeId` from the footer line "Your session id is … and your bridge id is …". When the prompt has no such line, the inbox is off, so skip this step.
    - Leave `blocking` false, so the ask closes at once. Loupe does not put the report in the inbox for you.
13. End with a short reply that gives the report `url` and the number of suggestions.

## Conflict check

The checks of `workflow_get` that start `Conflict check:` name what the workflow needs from a repository. The list below says where Loupe defines each need, what to read and what counts as a conflict. Compare only the checks that `workflow_get` returns, and only with these needs. Judge no other practice of the repository.

Read what is there, and assume that none of it exists. Change nothing.

1. `Conflict check: branch rules`. The `merge-ready` rule needs `pr.approval_check_passed`, `pr.checks_passed` and `pr.base_is_merge_target`. Read "Read the rules of the base branch" in the forge adapter. A conflict is a gap in the rules that the check names. A required approving review is one, because the app posts `Loupe / Approval` as the approval.
2. `Conflict check: merge method`. The `merge` write takes the first method of its `methods` list that the repository and the rules of the base branch allow. Read the same forge section. A conflict is a base branch that allows none. A profile `Merge` or `Epics` method that the base branch does not allow is also a conflict.
3. `Conflict check: merge queue`. The merge stage accepts only the state `merged`, and never turns on auto-merge. Read the same forge section. A merge queue on the base branch is a conflict. Name the "Merge" section of the forge adapter in the evidence.
4. `Conflict check: other review bots`. The `fix-*` rules run on `pr.changes_requested`, and the merge rules wait while it holds. A required check of another bot also holds `pr.checks_passed`. Read the CI config, the required checks and the reviews on the recent pull requests. A conflict is another reviewer that requests changes on its own, or a bot that merges or marks ready.
5. `Conflict check: agent instruction files`. Read each file that the profile `Instruction files` section names, and each agent instruction file in the repository. Compare them with the skills of the stages. A conflict is a line that forbids what a stage does, or demands what a stage forbids. Examples are a rule against a draft pull request, a rule that a worker moves its card, or a rule to push with `--force`.
6. `Conflict check: repository hooks`. Read each hook that the repository holds or installs, such as a git hook folder, a hook manager config or the hook settings of an agent harness. Run none of them. The commands that a stage runs are in `../loupe-stage-implementation/references/commands.md` and in the forge adapter. A conflict is a hook that refuses one of those commands, or that needs a tool the worker lacks.
7. `Conflict check: CI and the gate`. The `fix-in-implementation` and `fix-in-review` rules run on a failed required check. Read the required checks and the CI config, and compare them with the profile `Gate` section. A conflict is a required check with no gate command. It costs a fix round for each failure that the gate could catch.
8. `Conflict check: repository profile`. `../loupe-stage-implementation/references/commands.md` lists the required sections, and a stage stops when one is missing. Compare each profile, also a hand-written one with no marker. A conflict is a missing section, or a value that the repository does not show, such as a gate command or a base branch that does not exist.
9. `Conflict check: workflow template`. Read `template`, `columns`, `epicBranch` and `approvalCoversConflictResolution` from `workflow_get`. A conflict is a profile `Board` slug that names no column, a profile `Epics` branch pattern that differs from `epicBranch`, or a profile line that says otherwise about the approval.

Report each need as one finding:

- When the repository fits, the status is `ready`. The evidence names what you read.
- When you cannot read a setting, the status is `suggested`. A missing permission and a forge adapter with no such section are examples. The evidence quotes the command and its error, says that the setting could not be read, and asks the owner to check it. Never guess the value.
- When the repository conflicts, the status is `suggested`. The evidence quotes what you read and what the workflow needs. It names the options for the owner: change the repository, or change the profile. Name the option to change the workflow template when it applies. Do not choose for the owner.

A change that only the owner can make, such as a branch rule or a merge setting, goes in the evidence with no card (step 10). Each other change gets a proposal, and its body names the same options.

## Revising a report

The prompt of a revision names the kind `discovery-revise`. The owner asked for changes to the report, and the run is `revising`.

1. Read the report document with `document_get`. The `discovery` field of `readiness_get` names the card, and the card links the document with the tag `readiness-report`.
2. Read the comments with `document_get_review`. Address each comment, and check the repository again where a comment asks for it.
3. Call `readiness_report_submit` with the same `runId`, and the whole revised `workflow`, `findings`, `proposals` and `summary`. Loupe stores a new version of the same document, and replaces the proposals that made no card.
4. End with the report `url`. Skip step 12 of the steps above, because the owner already has the report in the inbox.

If the owner approves the report while you revise it, the tool refuses your submit. Report the refusal and stop.

## When something fails

A tool refusal names its fix. When the fix is a change that discovery must not make, stop and report the refusal in your final reply.

Two refusals of `readiness_report_submit` mean that the run takes no report. They are "This run does not wait for a report now" and "This run has a report already". A revising run takes a report. Do not retry, and do not start a new run. Report the refusal and stop.
