---
description: Insurance rule of loupe-stage-fix-round. A spec finding with needsPerson goes to the owner as a blocking inbox question after a search, and the round stops blocked with no push.
tags: [insurance, loupe-stage-fix-round]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-fix-round skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042) in project shop (projectId 01proj00-0000-7000-8000-000000000001). Pull request https://github.com/acme/shop/pull/7 needs a fix: agent-review. Loupe instance https://loupe.example.

The head commit of the pull request is 5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f. You already did steps 1 to 8 of the code round. The pull request is mergeable, no check fails, no review item is open and no live-review note is pending. Read the agent review of the head with card_get.

You fixed the off-by-one finding with a test, and committed it as 9a8b7c6. You have not pushed. This session has no shell. Handle the remaining finding now, then give the final reply.
