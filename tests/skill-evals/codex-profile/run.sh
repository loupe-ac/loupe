#!/usr/bin/env bash
# Runs the loupe-profile skill in Codex on the foreign repository, and applies the
# graders of the foreign-profile-agent case. It costs one Codex run of the logged-in
# account, reads no config.toml, and keeps the run folder for a person to read.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../../.." && pwd)
case_dir=$root/tests/skill-evals/evals/foreign-profile-agent
skill=$root/plugins/loupe-lifecycle/skills/loupe-profile/SKILL.md
run=$(mktemp -d "${TMPDIR:-/tmp}/codex-profile-XXXXXX")
mkdir "$run/repo"

(cd "$run/repo" && source "$root/tests/skill-evals/evals/foreign-lib/repo.sh" && NO_PROFILE=1 make_repo)

# The prompt of the case, after its front matter, names the skill by its path, as `loupe init` does.
prompt=$(awk 'seen >= 2; /^---$/ { seen++ }' "$case_dir/prompt.md")
prompt=${prompt/Use the loupe-profile skill./Use the loupe-lifecycle:loupe-profile skill, at $skill.}

codex exec --ignore-user-config --sandbox workspace-write --json --cd "$run/repo" --output-last-message "$run/last.txt" \
    -c 'mcp_servers.loupe.command="node"' \
    -c "mcp_servers.loupe.args=[\"$here/mock-loupe.mjs\",\"$case_dir/mocks/loupe/workflow_get.md\",\"$run/mcp-calls.log\"]" \
    -c 'mcp_servers.loupe.default_tools_approval_mode="approve"' \
    "$prompt" </dev/null >"$run/events.jsonl" 2>"$run/stderr.txt"

profile=$run/repo/.loupe/lifecycle.md
failed=0
grade() {
    if eval "$2"; then echo "pass $1"; else echo "FAIL $1"; failed=1; fi
}
grade skill-read 'grep -q "loupe-profile/SKILL.md" "$run/events.jsonl"'
grade workflow-read 'grep -q workflow_get "$run/mcp-calls.log"'
grade profile-written 'test -f "$profile"'
for section in 'Instruction files' Environment Gate 'Code review' 'Pull request' Board Merge Repair; do
    grade "section $section" 'grep -q "^## $section\$" "$profile"'
done
grade gate-from-contributing 'grep -q "python3 -m unittest discover -s tests" "$profile"'
grade no-guess-marker '! grep -q "detected, check it" "$profile"'
echo "run folder: $run"

exit "$failed"
