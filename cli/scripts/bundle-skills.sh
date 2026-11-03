#!/bin/sh
# Copies the plugin folders into the folder that the CLI embeds.
# Usage: bundle-skills.sh [repository root]
set -eu

root=${1:-$(git rev-parse --show-toplevel)}
dest=$root/cli/internal/skills/bundle

find "$dest" -mindepth 1 -not -name .placeholder -delete 2>/dev/null || true

for plugin in "$root"/plugins/*/; do
	name=$(basename "$plugin")
	# -L turns a symlink into a copy, because go:embed refuses a symlink.
	cp -RL "$plugin" "$dest/$name"
done
