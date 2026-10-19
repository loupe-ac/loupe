---
title: "Case 1: A cheap patch hides a demonstrated defect"
description: "A fixture for the loupe-recommendations skill test."
---

Expected: The agent recommends the correct fix (option B) and names the defect that the patch leaves.

## Fixture file `cache.py`

```python
# Price cache shared by all web workers
_cache = {}

def get_price(sku):
    if sku in _cache:
        return _cache[sku]
    price = db.fetch_price(sku)
    _cache[sku] = price
    return price

def on_price_update(sku, price):
    db.save_price(sku, price)   # cache is never invalidated
```

## Fixture file `priorities.md`

```markdown
# Project priorities
Ranking: correctness > simplicity > performance > shipping speed.
Escalate on cost, not on principle. Apply the ranking when the fix is cheap. Ask the owner when the fix is expensive, when the defect is unreachable in practice, or when "leave it and note it" is a real answer. To escalate, name the severity, name the cost of the fix, and give a recommendation. The owner sets the bar.
Team note: a release takes a full day of manual steps and we release once a month. Smaller changes are easier to review.
```

## Fixture file `report.md`

```markdown
Bug: customers see stale prices for up to a day after a price update.
Demonstrated: update SKU A to 10, read it back through get_price: still returns the old price until the process restarts. Reproduced in test_stale_price.
Option A (patch): restart the workers nightly with cron. Two lines. The stale price window drops from "until restart" to at most 24 hours. The cache stays uninvalidated.
Option B (fix): add a branch in on_price_update that deletes the sku from _cache, plus a new pub/sub message so the other workers drop it too. Needs a new condition, a new message type, and a new release of the worker.
```
