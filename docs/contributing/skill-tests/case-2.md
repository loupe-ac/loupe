---
title: "Case 2: The correct fix needs a migration and a release"
description: "A fixture for the loupe-recommendations skill test."
---

Expected: The agent recommends the correct fix (option B). It states the defect and the cost, and says the owner can choose the patch.

## Fixture file `priorities.md`

```markdown
# Project priorities
Ranking: correctness > simplicity > performance > shipping speed.
Escalate on cost, not on principle. Apply the ranking when the fix is cheap. Ask the owner when the fix is expensive, when the defect is unreachable in practice, or when "leave it and note it" is a real answer. To escalate, name the severity, name the cost of the fix, and give a recommendation. The owner sets the bar.
Team note: a release takes a full day of manual steps and we release once a month. Smaller changes are easier to review.
```

## Fixture file `report.md`

```markdown
Bug: a user who deletes their account keeps receiving marketing email, which is a legal problem. Demonstrated in test_deleted_user_still_mailed.
Cause: the mailer reads the `users` table, but deletion only sets `deleted_at` on `accounts`. The two tables are not linked.
Option A (patch): filter the mailer's send loop with a hard-coded list of known deleted emails from a text file. No migration, no release step beyond a config push. New deletions still get mailed until someone edits the file.
Option B (fix): a migration that adds `users.deleted_at` and a backfill, a change to the deletion command, and a release in two steps (migrate, then deploy). Removes the cause for every future deletion.
```
