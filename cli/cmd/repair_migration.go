package cmd

import "github.com/loupe-ac/loupe/cli/internal/rules"

// migrateRepairKey renames the work key repair to recover before the rule file
// loads. A failure leaves the file as it was, and rules.Load then refuses the
// old key with a message that names the new one.
func migrateRepairKey(path string, bl *bridgeLog) {
	changed, err := rules.MigrateRepairKey(path)
	if bl == nil {
		return
	}
	switch {
	case err != nil:
		bl.log.Warn("repair_key_migration_failed", "rules", path, "error", err.Error())
	case changed:
		bl.log.Info("repair_key_migrated", "rules", path)
	}
}
