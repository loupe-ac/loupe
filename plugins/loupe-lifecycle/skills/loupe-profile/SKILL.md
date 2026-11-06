---
name: loupe-profile
description: "Use when a prompt names loupe-profile, when `loupe init` starts a profile agent, or when `.loupe/lifecycle.md` is missing, lacks a section or holds a value marked \"owner to fill in\"."
---

# Repository profile

Write `.loupe/lifecycle.md` with the owner, so that every stage skill finds the values it needs. The file holds the values of this repository. It holds no guess that reads like a fact.

## Rules

1. Read the repository before you ask. Ask the owner only for a value that the repository does not show.
2. Show every value in one grouped view, with the source of each. Ask one question for each unknown value, and nothing else.
3. State a value as the repository defines it. Never invent a command, a branch, a check or a column. A value you cannot find stays unknown until the owner answers.
4. Write the file only after the owner approves the view. Write one numbered list for each section.
5. An existing file is somebody's work. Keep every value that is right, and change only what the repository or the owner contradicts.
6. Get the file to the remote as the rules of this repository say. Read its instruction files first. Follow any approval they require. Loupe needs one outcome: a worker that clones the remote can read the file.

## Steps

1. Call `workflow_get`. It returns the template `key`, the `columns` and the `epicBranch` pattern. These give the `Board` and `Epics` values.
2. Read the repository: the agent instruction files, the build and test files, the CI configuration, the contribution guide, the git remote, and any existing `.loupe/lifecycle.md`.
3. Collect the value of each section below, with its source.
4. Show the grouped view. For each section list its values, the source of each, and the unknowns.
5. Ask the owner one question for each unknown. Offer the likely answers that you found.
6. Write the file. Check it holds every required section and no "owner to fill in" line that the owner answered.
7. Write `.loupe.yaml` when the repository lacks it. `loupe project link` writes it for the project that `loupe init` linked.
8. Get both files to the remote, as rule 6 says. Report what is on the remote and what is not.

## Sections

A section starts with a `##` heading of the exact name and holds a numbered list. A stage reads each list as instructions, so write short, direct sentences.

| Section | Required | What it holds | Source |
|---|---|---|---|
| `Instruction files` | yes | The files and skills a worker loads before it changes code, and the writing style | The agent instruction files and the contribution guide |
| `Environment` | yes | A check that the worker is in its own folder, or "none" | The repository tooling for worktrees or containers |
| `Gate` | yes | The base branch, the commands that must pass before a push in order, and the required checks that the merge counts | The task runner, the CI configuration and the rules of the base branch |
| `Code review` | yes | A review step before the push and its pass rule, or "none" | The contribution guide |
| `Pull request` | yes | Draft or ready, the title format, the body rules, the branch pattern | The contribution guide and the history of merged pull requests |
| `Board` | yes | The column slugs a stage reads or moves a card to | `workflow_get` |
| `Merge` | yes | The merge method, the approver whose approval counts, the base branches that may merge | The rules of the base branch |
| `Repair` | yes | The actions a repair worker may take | The tooling of the repository |
| `Changelog` | no | The changelog file and its check, or "none" | The repository |
| `Forge` | no | The forge name, only when the remote host does not show it | The remote |
| `Epics` | no | The epic branch pattern, the merge method of a child, the epic preview | `workflow_get` |
| `Visual check` and `Preview` | no | How a worker proves a changed page. `Preview` can also hold `Line patterns`, one extended regex for each accepted line form in a fenced block, `Data line patterns`, the line forms that a state with records accepts in a fenced block, and `Excluded paths`, regexes for changed paths that do not count. The pre-push check reads all three | The repository, only when it has pages |
| `Documents` | no | The tags of design documents | `workflow_get`, only when they differ from the default |

## Unknown values

1. Write a value you found with its source in the view, never in the file.
2. A value that stays unknown after the question is written as `<name>: owner to fill in.` A stage that needs it stops blocked, and discovery asks the owner for it.
3. Never write "none" for a value you did not check. "None" means the repository has no such thing.
4. Never write "detected, check it". The view is the place to check a value, and the file holds only what the owner approved.

## Review

When the prompt says the file exists, read it first. Compare every value with the repository and with `workflow_get`. Show the differences in the grouped view, and change only what the owner approves.

## Report

End with a short report: the file written, the values the owner answered, any value still open, and where the files are on the remote.
