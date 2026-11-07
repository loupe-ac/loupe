#!/usr/bin/env bash
# Runs routing-check.sh against small profile files, one per case. It needs bash and no network.
set -u

root=$(cd "$(dirname "$0")/../.." && pwd)
script=$root/plugins/loupe-lifecycle/skills/loupe-stage-product-design/references/routing-check.sh
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/.claude/skills/x"
touch "$work/.claude/skills/x/SKILL.md"
fail=0

# check <name> <expected exit> <expected output fragment, or empty> <profile text>
check() {
  printf '%s\n' "$4" >"$work/profile.md"
  out=$(cd "$work" && bash "$script" profile.md 2>&1)
  code=$?
  if [ "$code" != "$2" ] || { [ -n "$3" ] && ! grep -qF "$3" <<<"$out"; }; then
    echo "FAIL $1: exit $code, output: $out"
    fail=1
  fi
}

check known-heading 0 "" $'## Routing\n### Merge\nLoad skill `x` always.'
check unknown-heading 1 "unknown stage heading" $'## Routing\n### Reivew\nLoad skill `x` always.'
check repeated-heading 1 "repeated stage heading" $'## Routing\n### Merge\nLoad skill `x` a.\n### Merge\nLoad skill `x` b.'
check heading-outside-routing 0 "" $'## Notes\n### Whatever\n## Routing\n### Review\nLoad skill `x` always.'
check malformed-line 1 "malformed" $'## Routing\n### Review\nLoad x.'
check missing-skill 1 "missing y" $'## Routing\n### Review\nLoad skill `y` always.'

exit $fail
