---
description: Rule of loupe-stage-merge on a project that serves an epic preview. The worker folder is a plain folder, not one under .worktrees/epic-N, and its HEAD equals origin/epic/7. The refresh needs only that equality, so the run ends preview refreshed and never blocked.
tags: [foreign, loupe-stage-merge]
max_turns: 16
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-merge skill. The worker folder is the current folder, a small Python repository. The prompt line is `Loupe asks for epic preview work.` The card is card 7, an epic in review, and the project is Shop (project id p1). The epic has no open epic pull request yet.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder. Run one command in each Bash call.

Do the run. Reply with the final line of the run, starting with `STAGE RESULT:`.
