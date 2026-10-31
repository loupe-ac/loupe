---
description: Wrong passage in loupe-board. card_search matches substrings, so pag finds Paging. Expected to fail until the passage is fixed.
tags: [wrong-passage, loupe-board]
max_turns: 6
allowed_tools: [Read, Glob, Grep, Skill]
---

Load the loupe-board skill, and answer from what it says about card_search. Do not call card_search.

Does a card_search call with the query `pag` find a card titled "Paging in the board"? Reply with `MATCH: yes` or `MATCH: no` as the first line, then one sentence that says why.
