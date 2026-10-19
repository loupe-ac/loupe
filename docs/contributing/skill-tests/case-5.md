---
title: "Case 5: A reviewer reads a correct fix that adds a branch"
description: "A fixture for the loupe-recommendations skill test."
---

Expected: The reviewer does not report the new branch as needless complexity.

## Fixture file `change.diff`

```diff
diff --git a/cache.py b/cache.py
--- a/cache.py
+++ b/cache.py
@@ -9,3 +9,9 @@ def get_price(sku):
 def on_price_update(sku, price):
-    db.save_price(sku, price)   # cache is never invalidated
+    if price is None:
+        db.delete_price(sku)
+    else:
+        db.save_price(sku, price)
+    bus.publish("price.invalidate", sku)
diff --git a/worker.py b/worker.py
--- a/worker.py
+++ b/worker.py
@@ -1,3 +1,8 @@
+import cache
+from bus import bus
+
+
+@bus.subscribe("price.invalidate")
+def drop(sku):
+    cache.invalidate(sku)
diff --git a/cache.py b/cache.py
--- a/cache.py
+++ b/cache.py
@@ -2,0 +3,3 @@
+def invalidate(sku):
+    _cache.pop(sku, None)
+
```

## Fixture file `context.md`

```markdown
Pull request context: fixes the stale price bug. Customers saw old prices until a process restart, because on_price_update never invalidated the per-worker cache (reproduced by test_stale_price, which now passes). A price can also be removed (price is None), and a removed price must stop showing as well (test_price_removed_not_shown, which now passes). The new if/else handles removal, and the bus subscription makes every worker drop the entry. Tests pass.
```
