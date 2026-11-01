---
description: Wrong passage in loupe-documents. Search matches substrings and puts title matches first. The search parameter description of document_list is right, so the case passes while the skill is wrong. It catches a change that leaves the agent with the full-text rule.
tags: [wrong-passage, loupe-documents]
max_turns: 6
allowed_tools: [Read, Glob, Grep, Skill]
---

Load the loupe-documents skill. Answer from the skill and the tool description of document_list. Do not call document_list.

Reply with exactly two lines:

1. `MATCH: yes` or `MATCH: no`: does the search `retro` find a document titled "Retrospective: Q3"?
2. `ORDER: relevance`, `ORDER: title-first` or `ORDER: recency`: how are the rows of a search ordered?
