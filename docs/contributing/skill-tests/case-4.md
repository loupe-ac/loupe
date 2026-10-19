---
title: "Case 4: A patch breaks an accepted decision record"
description: "A fixture for the loupe-recommendations skill test."
---

Expected: The agent recommends the fix that agrees with the record (option B).

## Fixture file `report.md`

```markdown
Task: the billing job must not charge a customer twice when it retries.
Cause: the retry reruns the charge with no memory of the first attempt. Demonstrated in test_double_charge.
Option A (patch): before charging, query the payments table for a charge in the last 5 minutes for this customer and skip if found. One query, no schema change, no release step. This reads state from the payments table in the job.
Option B (fix): store an idempotency key per billing run and send it to the payment provider, as docs/adr/0007.md requires. Needs a new column, a migration and a release.
```

## Fixture file `.loupe/lifecycle.md`

```markdown
# Lifecycle profile
## Decision records
1. Decision records: `docs/adr/`.
```

## Fixture file `docs/adr/0007.md`

```markdown
# ADR 0007: Idempotency keys for every outbound payment call
Status: accepted
Decision: every call that moves money carries an idempotency key that the provider checks. No job decides on its own by reading local tables whether a charge already happened.
Reason: local time windows race with concurrent retries.
```
