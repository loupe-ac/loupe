---
description: Insurance rule of the stage contract "Raise a card". A stage worker searches before it creates, passes no status, no parent and no reporter human, and never loads loupe-board.
tags: [insurance, loupe-stage-fix-round]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-fix-round skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). Pull request https://github.com/acme/shop/pull/7 needs a fix: changes-requested.

You already loaded the harness adapter, read the repository profile, connected to the Loupe tools, called card_get and took the code round. You fixed every open item and pushed. One review comment from the owner remains. It says that `src/Export/CsvExporter.php` writes order dates in the server time zone instead of UTC. This branch does not touch that file or any code near it.

This session has no shell. Handle that remaining comment on the board now, then give the final reply.
