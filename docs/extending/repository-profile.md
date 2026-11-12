---
title: "Repository profile"
description: "The .loupe/lifecycle.md file holds the skill routing table of one repository that the stage skills of the Loupe plugin read."
---

The stage skills of the Loupe plugin hold the procedure of each stage. They
run in every repository that uses Loupe, so they hold no command, branch or
column name of one repository. Each repository keeps those rules in its own
instruction files and skills. Its profile, `.loupe/lifecycle.md`, at the
repository root, tells each stage which of those skills to load.

A worker reads the profile at the start of each stage. It first runs
`routing-check.sh`. When a skill that the routing table names is missing from
the repository, the worker stops with a line such as
`STAGE RESULT: blocked: routed skill project-backend missing`. The card then
shows the block, and nothing changes in the repository.

The profile holds a skill routing table, not a copy of your rules. Your
instruction files and skills hold the rules, such as the gate, the changelog,
the pull request format and the writing style. The table tells each stage which
skill to load, and when.

## The routing table

The `## Routing` section has one `###` heading for each stage. The stage names
are `Product design`, `Tech design`, `Implementation`, `Fix round`, `Review`,
`Design review`, `Merge`, `Epic preview` and `Discovery`. Under a heading, write
one line for each skill, in this form:

```markdown
Load skill `<name>` <when>.
```

`<name>` is a skill of the repository, under `.claude/skills/<name>/SKILL.md` or
`.agents/skills/<name>/SKILL.md`. `<when>` is free text that says when the stage
loads the skill, such as "when the diff changes `e2e/`" or "before the push".
A plugin skill, such as `loupe:loupe-board`, has a colon in its name. The check
skips it, because the repository cannot hold it. A stage with no heading loads
nothing.

```markdown
## Routing

### Implementation
Load skill `project-backend` when the diff changes `src/`.
Load skill `working-with-prs` before the push.

### Merge
Load skill `working-with-prs` before the merge.
```

The check also prints the number of each line that breaks the form, and the
stage then stops with `blocked: routing line malformed`. A file with no
`## Routing` section passes.

## Other sections

Two sections are optional, and a missing one blocks nothing.

1. `Forge` names the forge with one value, such as `github`. Without it, the
   worker reads the forge from the pull request link of the card, then from
   the host of the git remote.
2. `Documents` names the tags that mark a design document. The first line
   gives the tag of the product design, and the second gives the tags of the
   tech design. Without the section, the tags are `product-design`, and
   `tech-design` with `decisions`. Workflow rules match these tags, so set
   them only when your workflow template matches other tags.

The preview method and the visual check live in a skill of the repository, such
as `project-review`. Route that skill from the `Implementation`, `Fix round`,
`Review` and `Epic preview` headings of the routing table. Without a routed
skill, no stage checks previews.

A stage ignores an older section, such as `Instruction files`, `Gate`,
`Pull request`, `Board`, `Merge`, `Epics`, `Visual check` or `Preview`. Remove it when your routing table
and instruction files hold the same rules. The stages use the column slugs
`product-design`, `tech-design`, `implementation`, `in-review`, `backlog` and
`done`.

## Draft pull requests and the agent review

The Lifecycle workflow marks a pull request ready only when it is a draft, the
agent review passed and the checks are green. So a project on the Lifecycle
workflow with agent review on states in its instruction files that a pull
request opens as a draft and that an agent never marks it ready. A pull request
that opens as ready skips the agent review gate.

## Environment steps

The bridge prepares the worker's folder with the `before` command of a work
entry, and removes it with a `teardown` entry. The
[command-line bridge](cli-bridge.md) describes both. The profile does not
create or remove the folder, and a stage proves that the worker is not in the
main checkout without a profile step.

The refresh after a sync that brings new commits, such as a dependency install or a database migration, is not a profile step. Set the `refresh` field of the work entry in the bridge rule file. The stage runs `loupe env refresh`, and the bridge runs that command in the worker folder. See [refresh and reset commands](cli-bridge.md#refresh-and-reset-commands).

## Write the profile with an agent

`loupe init` starts an agent that looks for blockers, when the workflow of the
project needs a profile and the file is missing. The workflow template says
whether it needs a profile: Lifecycle does, and Simple does not. The agent writes
nothing to `.loupe/lifecycle.md`, and `loupe init` no longer checks the file for
sections or for a line that reads "owner to fill in". A stage that routes a skill the
repository lacks stops blocked. The recovery stage reads no profile
section. It carries its three actions itself, so an old `Recovery` or `Repair`
section is ignored.

A blocker is a thing that makes a worker fail on its first run, or does damage.
The list is closed: the merge question, whether Loupe can read the branch rules,
a conflict with a workflow requirement, and a worktree hook to carry over. The
agent asks one question per turn about a blocker. It changes nothing in your
tooling or workflow without your approval, and it proposes each edit to an
instruction file or a project skill on its own. When an instruction file
contradicts a workflow requirement, you choose one of three answers. You can
replace the existing instructions with the Loupe rule, update them so they
agree with it, or keep them as they are. A kept requirement stays a problem in
the summary.

The agent puts every change that you confirm in one pull request from the branch
`loupe/init`, titled `Set up Loupe`. Its body opens with `loupe init made these changes, as you confirmed in the terminal:` and lists each change with its file and reason. It ends with one of two fixed lines. When the pull request holds the merge setting, the line says the setting counts once the pull request merges. Otherwise it says `Merge this pull request to apply these changes.` The agent asks
whether to merge the pull request, and merges it with the first method that the
repository allows: squash, merge or rebase. When GitHub refuses, it prints the
reason and the link, and you merge on GitHub. A later run adds its commits to the
open pull request. When the branch holds confirmed changes that no pull request
carries, the next run keeps them. When that branch conflicts with the default
branch, the agent stops and tells you to resolve the conflict. Without `gh`, the
agent pushes the branch and prints the link that opens the pull request. A merge
setting that you chose counts once the pull request merges. Until then, the
inbox does not ask the merge question again. This needs CLI 2.7.0.

To write one by hand, create `.loupe/lifecycle.md`, and name the skills of
your repository. The example is for a Node project on GitHub with the
Lifecycle workflow.

```markdown
# Lifecycle profile

## Routing

### Implementation
Load skill `project-testing` when the diff changes `tests/`.
Load skill `working-with-prs` before the push.

### Fix round
Load skill `working-with-prs` before the push.

### Review
Load skill `project-testing` when the diff changes `tests/`.

### Merge
Load skill `working-with-prs` before the merge.
```

The profile of the Loupe repository itself is a longer example. It is at
[`.loupe/lifecycle.md`](https://github.com/loupe-ac/loupe/blob/main/.loupe/lifecycle.md)
in the Loupe repository.
