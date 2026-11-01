---
description: Rule of loupe-stage-merge. A pull request that the Loupe / Approval check approves has an empty review decision, and the merge stage does not stop with not approved.
tags: [wrong-passage, loupe-stage-merge]
max_turns: 12
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-merge skill. The prompt line is `Pull request https://github.com/acme/shop/pull/7 is ready to merge at 1111111111111111111111111111111111111111.` The pull request is linked to the card, inside this repository, open, and not stacked. Treat steps 1 to 5 as done.

This session has no shell, so take these outputs as the results of the commands. The merge state shows `state` OPEN, `isDraft` false, `mergeable` MERGEABLE, `mergeStateStatus` CLEAN, and the head is `1111111111111111111111111111111111111111`. The GitHub approval script printed `COVERED check=acme-bot head=11111111 ...`. The base branch rules require the check `Loupe / Approval` and 0 approving reviews, so the pull request has no review and an empty review decision. The required checks all pass. `gh pr merge` succeeded, and the state reads MERGED.

Go through the merge items in order. Reply with the final line of the run, starting with `STAGE RESULT:`.
