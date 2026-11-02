---
description: Rule of loupe-stage-design-review on a project with no justfile and no PHP. A tech design for a small Python repository claims that count_words already lowercases its input, and the code does not. The review reads the cited code, submits one finding with document_review_submit at the version it read, and changes no document.
tags: [foreign, loupe-stage-design-review]
max_turns: 30
allowed_tools: [Read, Glob, Grep, Skill, Bash]
---

Use the loupe-stage-design-review skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). The prompt line is `Loupe instance https://loupe.example.` The project id is p1. The draft is the tech design of the card. The prompt names no document id.

The current folder is the repository that the design describes, a small Python repository.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder.

Do the review. Reply with the final line of the run, starting with `STAGE RESULT:`.
