---
description: Insurance rule of loupe-stage-fix-round. After the push, a code round marks addressed the pending live-review note it fixed, and no other note.
tags: [insurance, loupe-stage-fix-round]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-fix-round skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). Pull request https://github.com/acme/shop/pull/7 needs a fix: changes-requested.

You already read the pull request. It is mergeable and no check fails. Its only feedback item is review 3011. The body of that review copies one live-review note and ends with the line `<!-- loupe-note:01note00-0000-7000-8000-00000000000a -->`. Read the notes of the card with card_get.

You fixed what that note asked in commit ab12cd3, ran the gate, and pushed it without force. This session has no shell. Finish the round now. Write any gh command in one fenced bash block instead of running it, then give the final reply.
