#!/usr/bin/env bash
# Runs the loupe-profile skill in Claude Code or Codex on the foreign repository, and
# grades the outcome: both files must be on main in the bare remote of the fixture, as a
# worker that clones it reads them. It costs one run of the logged-in account, reads no
# account config, and keeps the run folder for a person to read.
set -euo pipefail

agent=${1:-}
case "$agent" in
    claude | codex) ;;
    *)
        echo "usage: run.sh <claude|codex>" >&2
        exit 2
        ;;
esac

here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../../.." && pwd)
case_dir=$root/tests/skill-evals/evals/foreign-profile-agent
plugin=$root/plugins/loupe-lifecycle
skill=$plugin/skills/loupe-profile/SKILL.md
mock=$case_dir/mocks/loupe/workflow_get.md
run=$(mktemp -d "${TMPDIR:-/tmp}/profile-agent-$agent-XXXXXX")
mkdir "$run/repo"

(cd "$run/repo" && source "$root/tests/skill-evals/evals/foreign-lib/repo.sh" && NO_PROFILE=1 make_repo && write_project_file)

# The prompt of the case, after its front matter.
prompt=$(awk 'seen >= 2; /^---$/ { seen++ }' "$case_dir/prompt.md")

# The launch of `loupe init`, without the terminal. Only the lifecycle package loads,
# because the hooks of the core package call the real `loupe` CLI of the host.
run_claude() {
    cat >"$run/mcp.json" <<JSON
{"mcpServers":{"loupe":{"command":"node","args":["$here/mock-loupe.mjs","$mock","$run/mcp-calls.log"]}}}
JSON
    env -u CLAUDECODE -u CLAUDE_CODE_SESSION_ID -u LOUPE_SESSION_ID -u LOUPE_RUN_ID \
        claude -p --model claude-opus-5-5 --max-budget-usd 5 --output-format stream-json --verbose \
        --setting-sources project --strict-mcp-config --mcp-config "$run/mcp.json" --plugin-dir "$plugin" \
        --allowedTools 'Bash(./.bin/git:*)' 'Bash(git:*)' 'Bash(python3:*)' 'Bash(ls:*)' 'Bash(cat:*)' \
        'Bash(grep:*)' 'Bash(test:*)' 'Bash(mkdir:*)' Read Glob Grep Skill Write Edit mcp__loupe__workflow_get \
        -- "$prompt"
}

# `loupe init` passes Codex the path of the skill, because Codex has no package folder.
# In a terminal the owner approves a commit. `codex exec` has no owner, and its sandbox
# keeps .git read-only, so --add-dir grants that one folder.
run_codex() {
    codex exec --ignore-user-config --sandbox workspace-write --add-dir "$run/repo/.git" --json \
        --output-last-message "$run/last.txt" \
        -c 'mcp_servers.loupe.command="node"' \
        -c "mcp_servers.loupe.args=[\"$here/mock-loupe.mjs\",\"$mock\",\"$run/mcp-calls.log\"]" \
        -c 'mcp_servers.loupe.default_tools_approval_mode="approve"' \
        "${prompt/Use the loupe-profile skill./Use the loupe-lifecycle:loupe-profile skill, at $skill.}"
}

agent_exit=0
(cd "$run/repo" && "run_$agent") </dev/null >"$run/events.jsonl" 2>"$run/stderr.txt" || agent_exit=$?

failed=0
grade() {
    if eval "$2"; then echo "pass $1"; else echo "FAIL $1"; failed=1; fi
}

if [ "$agent" = claude ]; then
    skill_used='"name":"Skill","input":\{"skill":"[^"]*loupe-profile"'
else
    skill_used='loupe-profile/SKILL\.md'
fi

remote=(git --git-dir="$run/repo/.remote/origin.git")
profile=$run/remote-lifecycle.md
"${remote[@]}" show refs/heads/main:.loupe/lifecycle.md >"$profile" 2>/dev/null || rm -f "$profile"

grade agent-exit '[ "$agent_exit" -eq 0 ]'
grade skill-used 'grep -Eq "$skill_used" "$run/events.jsonl"'
grade workflow-read 'grep -q workflow_get "$run/mcp-calls.log"'
grade profile-on-remote 'test -f "$profile"'
grade project-file-on-remote '"${remote[@]}" cat-file -e refs/heads/main:.loupe.yaml 2>/dev/null'
for section in 'Instruction files' Environment Gate 'Code review' 'Pull request' Board Merge Repair; do
    grade "section $section" 'grep -q "^## $section\$" "$profile"'
done
grade gate-from-contributing 'grep -q "python3 -m unittest discover -s tests" "$profile"'
grade no-guess-marker 'test -f "$profile" && ! grep -q "detected, check it" "$profile"'
echo "run folder: $run"

exit "$failed"
