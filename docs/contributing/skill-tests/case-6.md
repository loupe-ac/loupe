---
title: "Case 6: A reviewer reads a patch that leaves a defect"
description: "A fixture for the loupe-recommendations skill test."
---

Expected: The reviewer reports the remaining defect as a finding.

## Fixture file `change.diff`

```diff
diff --git a/crontab b/crontab
--- a/crontab
+++ b/crontab
@@ -1,1 +1,2 @@
 0 * * * * /usr/bin/backup
+0 3 * * * systemctl restart webworkers
```

## Fixture file `context.md`

```markdown
Pull request context: fixes the stale price bug. Customers saw old prices after a price update until the process restarted (cache.py get_price caches forever, on_price_update never invalidates it; reproduced by test_stale_price, still failing on this branch). The change restarts the workers every night so the stale window is at most a day. cache.py is untouched. Tests other than test_stale_price pass.
```
