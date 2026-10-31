---
description: Wrong passage in loupe-board. card_search matches substrings, so pag finds Paging. The real tool description is right, so the case passes while the skill is wrong. It catches a change that leaves the agent with the stemmed-word rule.
tags: [wrong-passage, loupe-board]
max_turns: 6
allowed_tools: [Read, Glob, Grep, Skill]
---

Load the loupe-board skill. Answer from the skill and the tool description of card_search. Do not call card_search.

Does a card_search call with the query `pag` find a card titled "Paging in the board"? Reply with `MATCH: yes` or `MATCH: no` as the first line, then one sentence that says why.
