---
description: Rule of loupe-stage-implementation on a project that keeps no changelog. A small Python repository, with no justfile and a profile with no Changelog section, ends the implementation at the push with STAGE RESULT waiting. The run never stops blocked, never writes a changelog entry and never runs just.
tags: [foreign, loupe-stage-implementation]
max_turns: 40
allowed_tools: [Read, Glob, Grep, Skill, Bash, Write, Edit]
---

Run the loupe-stage-implementation skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt's column label is Implementation, and the project id is p1. The Loupe instance is https://loupe.example. The card links no pull request.

The current folder is the worker folder, a small Python repository. It starts on a detached HEAD at `origin/main`. Steps 1 to 6 and 9 to 11 are done: the tech design is approved, the build notes exist and are linked, and the sub-agents wrote the code. The code is an uncommitted change in the worker folder. Do steps 0, 7, 8, 12, 13 and 14 for real, in the shell of the worker folder.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder. Run one command in each Bash call.

The `gh` command is not available in this session. Take `gh pr list` as printing nothing. Take `gh pr create --draft` as printing `https://github.com/acme/tally/pull/12`. A push goes to a local remote, so it works.

Reply with the final line of the run, starting with `STAGE RESULT:`.
