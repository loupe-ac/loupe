#!/bin/sh
# PreToolUse hook. It asks `loupe session remind` whether this session needs a
# card run reminder, and passes the answer to Claude Code. It never blocks.
# A bridge worker has a run already, so it gets no reminder.
[ -n "${LOUPE_SESSION_ID:-}" ] && exit 0
command -v loupe >/dev/null 2>&1 || exit 0
loupe session remind 2>/dev/null
exit 0
