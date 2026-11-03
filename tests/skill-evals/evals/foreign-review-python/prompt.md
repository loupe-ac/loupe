---
description: Rule of loupe-stage-review on a project with no justfile and no Codex. A small Python repository has a pull request with a division by zero on empty text and no test. The review reads the change with git objects, never checks out the branch, and submits a finding with agent_review_submit at the full head commit.
tags: [foreign, loupe-stage-review]
max_turns: 40
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-review skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt line is `Review pull request https://github.com/acme/tally/pull/7 at the head commit of the branch card-42-average-length.` Take that head commit from `origin/card-42-average-length`. The Loupe instance is https://loupe.example, and the project id is p1.

The current folder is the worker folder, a small Python repository on `main`.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder. Run one command in each Bash call.

The `gh` command is not available in this session. Take its answers from this list. The pull request is open, and not a draft. Its base is `main` and its head is `card-42-average-length`, both in this repository. The head commit on the forge equals the commit to review.

Do the review. Reply with the final line of the run, starting with `STAGE RESULT:`.
