---
description: Rule of loupe-stage-merge on a project that serves no epic preview. A small Python repository whose profile says Epic preview none ends an epic preview request with STAGE RESULT unchanged, and never stops blocked.
tags: [foreign, loupe-stage-merge]
max_turns: 14
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-merge skill. The worker folder is the current folder, a small Python repository. The prompt line is `Loupe asks for epic preview work.` The card is card 7, an epic in review, and the project is Shop (project id p1).

Do the run. Reply with the final line of the run, starting with `STAGE RESULT:`.
