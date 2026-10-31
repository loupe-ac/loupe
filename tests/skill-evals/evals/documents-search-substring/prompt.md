---
description: Wrong passage in loupe-documents. document_list search matches substrings and puts title matches first. Expected to fail until the passage is fixed.
tags: [wrong-passage, loupe-documents]
max_turns: 6
allowed_tools: [Read, Glob, Grep, Skill]
---

Load the loupe-documents skill, and answer from what it says about the `search` argument of document_list. Do not call document_list.

Reply with exactly two lines:

1. `MATCH: yes` or `MATCH: no`: does the search `retro` find a document titled "Retrospective: Q3"?
2. `ORDER: relevance`, `ORDER: title-first` or `ORDER: recency`: how are the rows of a search ordered?
