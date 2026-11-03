#!/bin/sh
# Refuses a change under a plugin folder when the version of that plugin is not greater than on the base.
# Usage: plugin-version-check.sh <base ref>
set -eu

base=$1
here=$(dirname "$0")
status=0

for plugin in plugins/*/; do
	name=$(basename "$plugin")
	manifest=plugins/$name/.claude-plugin/plugin.json
	git diff --quiet "$base"...HEAD -- "plugins/$name" && continue

	old=$(git show "$base:$manifest" 2>/dev/null | sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' | head -n 1)
	new=$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "$manifest" | head -n 1)
	# A plugin that the base lacks is new, so any version is a bump.
	if [ -n "$old" ] && ! sh "$here/version-greater.sh" "$new" "$old"; then
		echo "::error::plugins/$name changed, and its version $new is not greater than $old. Bump the version in $manifest and in the Codex manifest."
		status=1
	fi

	codex=$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "plugins/$name/.codex-plugin/plugin.json" | head -n 1)
	if [ "$codex" != "$new" ]; then
		echo "::error::plugins/$name has version $new for Claude Code and $codex for Codex."
		status=1
	fi
done

exit $status
