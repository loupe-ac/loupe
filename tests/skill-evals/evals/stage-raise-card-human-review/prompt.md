---
description: Insurance rule of the stage contract "Raise a card". A card that a stage worker raises for a visible change with a prototype turns on "Requires human review".
tags: [insurance, loupe-stage-fix-round]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-fix-round skill for card 42 (cardId 01a19328-0000-7000-8000-000000000042). Pull request https://github.com/acme/shop/pull/7 needs a fix: agent-review.

You already loaded the harness adapter, read the repository profile, connected to the Loupe tools, called card_get and took the code round. You fixed every finding in the code of this branch and pushed. One `pre-existing` finding remains. The empty state of the Orders page shows a bare "No data" line. The prototype at https://claude.ai/design/p/7f3c2a shows an illustration and an "Add your first order" button there. This branch does not touch the Orders page.

This session has no shell. Handle that remaining finding on the board now, then give the final reply.
