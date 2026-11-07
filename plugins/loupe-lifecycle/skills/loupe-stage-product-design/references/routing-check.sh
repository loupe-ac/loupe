#!/usr/bin/env bash
# Check the skill routing table of the repository profile.
# Usage: routing-check.sh [profile file]   (default: .loupe/lifecycle.md)
# Run it from the repository root.
# Exit 0: the file passes, or it is missing, or it has no "## Routing" section.
# Exit 1: a line is malformed, or a routed skill is missing. One line per problem:
#   malformed <line number>: <text>
#   missing <skill name>
# A plugin skill, whose name holds a colon, is skipped with one "note:" line.
set -u

file=${1:-.loupe/lifecycle.md}
[ -r "$file" ] || exit 0

rows=$(awk '
  /^## / { routing = ($0 == "## Routing"); next }
  !routing || /^### / || /^[[:space:]]*$/ { next }
  /^Load skill `[^`]+` .*[^[:space:]]\.[[:space:]]*$/ { split($0, p, "`"); print "name " p[2]; next }
  { print "bad " NR ": " $0 }
' "$file")

status=0
while IFS= read -r row; do
  case "$row" in
    "bad "*) echo "malformed ${row#bad }"; status=1 ;;
  esac
done <<<"$rows"

for skill in $(grep '^name ' <<<"$rows" | cut -d' ' -f2- | sort -u); do
  case "$skill" in
    *:*) echo "note: skipped plugin skill $skill" ;;
    *)
      if [ ! -f ".claude/skills/$skill/SKILL.md" ] && [ ! -f ".agents/skills/$skill/SKILL.md" ]; then
        echo "missing $skill"
        status=1
      fi
      ;;
  esac
done

exit $status
