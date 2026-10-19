---
title: "Case 3: Two options both remove the cause"
description: "A fixture for the loupe-recommendations skill test."
---

Expected: The agent recommends the simpler option (option A).

## Fixture file `report.md`

```markdown
Bug: the CSV export crashes on a name that contains a comma.
Cause: the exporter joins fields with "," and does not quote.
Option A: replace the hand-written join with the standard library csv writer. 3 lines changed, no new dependency.
Option B: write a small custom quoting function with its own escape rules and unit tests. About 40 lines, no new dependency.
Both options remove the cause and both pass the demonstrated failing test.
```
