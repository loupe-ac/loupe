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
  BEGIN {
    n = split("Product design|Tech design|Implementation|Fix round|Review|Design review|Merge|Epic preview|Discovery", k, "|")
    for (i = 1; i <= n; i++) known[k[i]] = 1
  }
  /^## / { routing = ($0 == "## Routing"); staged = 0; next }
  /^### / {
    if (!routing) next
    staged = 1
    h = substr($0, 5); sub(/[[:space:]]+$/, "", h)
    if (!(h in known)) { print "bad " NR ": " $0 " (unknown stage heading)"; next }
    if (h in seen) { print "bad " NR ": " $0 " (repeated stage heading)"; next }
    seen[h] = 1; next
  }
  !routing || /^[[:space:]]*$/ { next }
  !staged { print "bad " NR ": " $0 " (before any stage heading)"; next }
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
