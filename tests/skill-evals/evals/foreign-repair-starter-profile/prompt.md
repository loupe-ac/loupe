---
description: Rule of loupe-stage-repair on a project whose Repair section is the starter one. A failed run left an untracked file in the worker folder of a small Python repository. The starter guard says that git status must print nothing, and no starter action fixes it, so the run records a Repair paragraph on the card with card_update and ends blocked. It changes no file and never asks.
tags: [foreign, loupe-stage-repair]
max_turns: 30
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-repair skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt line is `Rule start-implementation failed with not-started.` The project id is p1.

The current folder is the worker folder, a small Python repository.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder.

Do the repair. Reply with the final line of the run, starting with `STAGE RESULT:`.
