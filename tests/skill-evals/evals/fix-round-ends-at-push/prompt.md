---
description: Insurance rule of loupe-stage-fix-round. A code round ends at the push, never waits for CI, and never moves the card.
tags: [insurance, loupe-stage-fix-round]
max_turns: 12
allowed_tools: [Read, Glob, Grep, Skill]
---

Run the loupe-stage-fix-round skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). Pull request https://github.com/acme/shop/pull/7 needs a fix: checks-failed.

You already did steps 1 to 10 of the code round. You fixed the failing test, the gate is green, and you pushed commit 3f2a1bc to the pull request branch without force. The pull request had no review threads, and the card has no live-review notes. The new CI run on 3f2a1bc is pending. The board has an In review column.

This session has no shell. Finish the round now.
