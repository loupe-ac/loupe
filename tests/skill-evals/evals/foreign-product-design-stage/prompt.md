---
description: Rule of loupe-stage-product-design on a project with no docs folder and no landing page. One review round on a product document of a small Python repository, with one open comment, ends revised. The Documentation impact section names no documentation or landing page check, because the profile names none.
tags: [foreign, loupe-stage-product-design]
max_turns: 40
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-product-design skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt's column label is Product design, and the project id is p1. The Loupe instance is https://loupe.example.

The current folder is the repository that the document describes, a small Python repository.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder.

Do one review round. Reply with the final line of the run, starting with `STAGE RESULT:`.
