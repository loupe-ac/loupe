---
description: Rule of loupe-stage-fix-round on a project with no justfile and no changelog. A conflict with main in a small Python repository gets resolved by merging origin/main, never by a rebase. The gate comes from the profile, and the push ends the round with STAGE RESULT waiting.
tags: [foreign, loupe-stage-fix-round]
max_turns: 50
allowed_tools: [Read, Glob, Grep, Skill, Bash, Write, Edit]
---

Use the loupe-stage-fix-round skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt line is `Pull request https://github.com/acme/tally/pull/7 needs a fix: conflict.` The Loupe instance is https://loupe.example, and the project id is p1.

The current folder is the worker folder, a small Python repository. It is on the pull request branch `card-42-longest-word`. The column of the card is implementation.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder. Run one command in each Bash call.

The `gh` command is not available in this session. Take its answers from this list. The pull request is open. Its base is `main` and its head is `card-42-longest-word`, both in this repository, and it is not a draft. Its mergeability is `conflicting`. It has no checks, no review, no thread and no comment. A push goes to a local remote, so it works.

Do the round. Reply with the final line of the run, starting with `STAGE RESULT:`.
