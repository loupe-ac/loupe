---
title: "Repository profile"
description: "The .loupe/lifecycle.md file holds the values of one repository that the stage skills of the Loupe plugin read."
---

The stage skills of the Loupe plugin hold the procedure of each stage. They
run in every repository that uses Loupe, so they hold no command, branch or
column name of one repository. Each repository writes those values in its
profile, `.loupe/lifecycle.md`, at the repository root.

A worker reads the profile at the start of each stage. When the file is
missing, or a section that a step needs is missing, the worker stops with a
line such as `STAGE RESULT: blocked: no Gate in .loupe/lifecycle.md`. The card
then shows the block, and nothing changes in the repository.

The profile is plain Markdown. Each section starts with a `##` heading that has
the exact section name, and holds a numbered list of rules. A worker reads the
rules as instructions, so write each one as a short, direct sentence.

## Sections

| Section | What it holds | Read by |
|---|---|---|
| `Instruction files` | The files and skills a worker loads before it changes code, such as `AGENTS.md` or `CONTRIBUTING.md`, and the writing style | Every stage, and the interactive design sessions |
| `Environment` | A check that the worker is in its own folder | Implementation, fix round |
| `Gate` | The base branch, and the commands to run before a push, in order | Implementation, fix round, merge, breakdown |
| `Code review` | A review step before the push, and the rule that it passed. Write "none" when the gate has no review step | Implementation |
| `Changelog` | Where a change records its changelog entry, and how to check it. Write "none" when the repository keeps no changelog | Implementation |
| `Pull request` | The title format, the body rules, whether a pull request opens as a draft or as ready, and an optional branch pattern, with `card-<number>-<slug>` as the default | Implementation, merge |
| `Board` | The column slugs that a stage reads or moves a card to: product design, tech design, implementation, review, default and terminal. A missing line means `product-design`, `tech-design`, `implementation` and `in-review` | Implementation, breakdown, product design |
| `Merge` | The merge method, the approver whose approval counts, and the base branches that the merge stage may merge into | Merge |
| `Repair` | The actions that a repair worker may take after a rule's work failed | Repair |

Six sections are optional. A missing optional section blocks nothing.

1. `Forge` names the forge with one value, such as `github`. Without it, the
   worker reads the forge from the pull request link of the card, then from
   the host of the git remote.
2. `Documents` names the tags that mark a design document. The first line
   gives the tag of the product design, and the second gives the tags of the
   tech design. Without the section, the tags are `product-design`, and
   `tech-design` with `decisions`. Workflow rules match these tags, so set
   them only when your workflow template matches other tags.
3. `Visual check` tells a worker how to screenshot a changed page: the paths
   that trigger the check, the app URL, how to sign in, the browser and the
   widths. Discovery also reads the browser tool from it, and checks for
   Playwright when the section names none.
4. `Preview` tells a worker how to prove a preview link for each state of a
   changed page: the paths that trigger the check, the body section and line
   form, and how to seed, mint and prove a link. Without the section, no
   stage checks previews.
5. `Epics` holds the epic branch pattern, how a child of an epic merges, and
   the epic preview. The merge stage needs it only when the project uses epic
   branches.
6. `Decision records` names where the project keeps its decision records and
   its priorities. The `loupe-recommendations` skill reads them to judge whether
   an option agrees with an accepted decision, and to settle a choice between
   options that are all correct. Without the section the skill skips that check.

## Draft pull requests and the agent review

The Lifecycle workflow marks a pull request ready only when it is a draft, the
agent review passed and the checks are green. So a project on the Lifecycle
workflow with agent review on writes this rule in its `Pull request` section:

```markdown
1. Open it as a draft. Never mark it ready, because the workflow does that.
```

A pull request that opens as ready skips the agent review gate.

## Environment steps

The bridge prepares the worker's folder with the `before` command of a work
entry, and removes it with a `teardown` entry. The
[command-line bridge](cli-bridge.md) describes both. The profile does not
create or remove the folder.

The `Environment` section holds one optional step that runs inside a stage: a folder check, which proves that the worker is not in the main checkout. For git worktrees, the two lines of `git rev-parse --path-format=absolute --git-dir --git-common-dir` differ.

The refresh after a sync that brings new commits, such as a dependency install or a database migration, is not a profile step. Set the `refresh` field of the work entry in the bridge rule file. The stage runs `loupe env refresh`, and the bridge runs that command in the worker folder. See [refresh and reset commands](cli-bridge.md#refresh-and-reset-commands).

Write "none" for the folder check when your repository does not need it.

## A starter profile

`loupe init` writes a starter when the repository has none. A value it guessed is
marked "detected, check it". A line that reads "owner to fill in" holds no value,
such as the merge method. A stage that needs that value stops as if the section
were missing. Its `Repair` section allows only `loupe env reset`, when the `repair`
work entry sets `reset`, and the epic sync of the merge stage. Add each other action
with its commands and its guards.

To write one by hand, copy this file to `.loupe/lifecycle.md`, and replace each value with the value
of your repository. The example is for a Node project on GitHub with the
Lifecycle workflow and its default column slugs.

```markdown
# Lifecycle profile

## Instruction files

1. Read `AGENTS.md`, and follow it.

## Environment

1. Folder check: none.

## Gate

1. Base branch: `main`.
2. Run `npm run lint`, and commit what it fixes.
3. Run `npm test`.

## Code review

1. None. The agent review runs after the push.

## Changelog

1. None.

## Pull request

1. Open it as a draft, against the base branch of the `Gate` section. Never mark it ready, because the workflow does that.
2. Write the title as `<type>: <summary>`.
3. Put the card URL in the body.

## Board

1. The column that holds a card in product design is `product-design`.
2. The column that holds a card in implementation is `implementation`.
3. The column that holds a pull request waiting for review is `in-review`.
4. The default column is `backlog`, and the terminal column is `done`.

## Merge

1. The merge method is `squash`.
2. Any approval from a person with write access counts.
3. Merge only a pull request whose base is `main`.

## Repair

1. The worker may reset the card branch to its base, when the branch holds no commit that the base lacks.
2. The worker never pushes to `main`, and never force-pushes.
```

The profile of the Loupe repository itself is a longer example. It is at
[`.loupe/lifecycle.md`](https://github.com/loupe-ac/loupe/blob/main/.loupe/lifecycle.md)
in the Loupe repository.
