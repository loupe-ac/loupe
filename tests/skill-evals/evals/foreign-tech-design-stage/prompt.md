---
description: Rule of loupe-stage-tech-design on a project whose profile names no tech design instructions and no deploy items. A card of a small Python repository with no product document gets a new tech design as a draft, and the run ends tech design created. It never blocks for a missing instruction file, and it writes no PHP, Symfony or landing page check into the design.
tags: [foreign, loupe-stage-tech-design]
max_turns: 40
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-tech-design skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt's column label is Tech design, and the project id is p1. The Loupe instance is https://loupe.example. The owner skipped product design for this card.

The current folder is the repository that the design describes, a small Python repository.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder.

Do the run. Reply with the final line of the run, starting with `STAGE RESULT:`.
