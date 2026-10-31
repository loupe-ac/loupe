---
description: Insurance rule of loupe-documents. An HTML comment inside a block element disappears, so the note must move out of the element.
tags: [insurance, loupe-documents]
max_turns: 10
allowed_tools: [Read, Glob, Grep, Skill]
---

Submit this draft to Loupe for review as a new document titled "Rollout plan". The HTML comment is a note that the reviewer must see.

```markdown
## Phases

<div class="callout">
<!-- Reviewer: the dates below are still estimates. -->
Phase 1 starts in week 2. Phase 2 starts in week 5.
</div>

## Risks

1. The payment provider may change its API in week 4.
```
