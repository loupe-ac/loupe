// Package flagname holds the body fields whose flag differs from their kebab-case name.
package flagname

// Renames maps a body field to the flag that sets it. The kebab-case of `format`
// is the shared output flag, so the formula format takes `--formula-format`.
var Renames = map[string]string{"format": "formula-format"}
