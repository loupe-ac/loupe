---
description: Rule of loupe-discovery on a project that has no browser and no Visual check section. A discovery run on a small Python repository submits its readiness report. It writes no finding about a browser tool and no proposal that installs one. It changes nothing in the repository or on the board.
tags: [foreign, loupe-discovery]
max_turns: 40
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-discovery skill. The prompt names project Tally (project id p1) and the discovery card 9 (cardId 01a19328-0000-7000-8000-000000000009). Your session id is 01ses000-0000-7000-8000-000000000001 and your bridge id is 01brg000-0000-7000-8000-000000000001.

The current folder is the repository of the project, a small Python repository with no web interface.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder.

The `gh` command is not available in this session, so a setting that only the forge shows cannot be read.

Do the discovery run. Reply with a short message that gives the report url and the number of suggestions.
