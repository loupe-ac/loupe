#!/bin/sh
# SessionEnd hook. Claude Code gives it 1.5 seconds, so it hands the hook JSON
# to `loupe session end` in the background and returns at once.
command -v loupe >/dev/null 2>&1 || exit 0
input=$(cat)
printf '%s' "$input" | nohup loupe session end >/dev/null 2>&1 &
exit 0
