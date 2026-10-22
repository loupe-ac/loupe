#!/usr/bin/env bash
# Refuses four Bash commands that the project rules forbid: whole-tree staging,
# bare docker compose in a worktree, `gh pr ready` and `gh pr merge --admin`.
# A rule is advice and a hook applies every time. Each refusal names the safe command.
set -o pipefail

input="$(cat)"
command="$(jq -r '.tool_input.command // empty' <<< "$input")"
[ -n "$command" ] || exit 0
cwd="$(jq -r '.cwd // empty' <<< "$input")"
. "$(dirname "$0")/lib/segments.sh"

deny() {
    jq -nc --arg reason "$1" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: "deny",
            permissionDecisionReason: $reason,
        },
    }'
    exit 0
}

stage_by_name="Stage files by name: git add <path> ... . Whole-tree staging (git add -A, git add ., git commit -a) can pick up changes that belong to other work."
pr_ready="Never run gh pr ready. The workflow marks a pull request ready when the agent review passes and the checks are green."
pr_admin="Never merge with --admin. A merge must pass the branch rules, and the merge stage merges the pull request of a card."
compose_in_worktree="Never run docker compose from a worktree, because all worktrees share one Docker stack. Use just worktree-up, just worktree-down or bin/worktrees/compose-exec.sh <command>. Only the read-only calls ps, logs, config, top, images, ls, port, version and events are allowed."

# True when the words hold a short-flag group, such as -Av, that has the letter.
has_short_flag() {
    local letter="$1"
    shift
    for word in "$@"; do
        case "$word" in
            --*) ;;
            -*"$letter"*) return 0 ;;
        esac
    done

    return 1
}

judge_git() {
    local -a words=("$@")
    local i=0
    while [ "$i" -lt "${#words[@]}" ]; do
        case "${words[i]}" in
            -C|-c|--git-dir|--work-tree|--namespace|--super-prefix|--config-env|--exec-path) i=$((i + 2)) ;;
            -*) i=$((i + 1)) ;;
            *) break ;;
        esac
    done
    local sub="${words[i]:-}"
    local -a args=("${words[@]:i+1}")
    case "$sub" in
        add)
            has_short_flag A "${args[@]}" && deny "$stage_by_name"
            for word in "${args[@]}"; do
                case "$word" in --all|.|./|:/|:/:) deny "$stage_by_name" ;; esac
            done ;;
        commit)
            has_short_flag a "${args[@]}" && deny "$stage_by_name"
            for word in "${args[@]}"; do
                [ "$word" = --all ] && deny "$stage_by_name"
            done ;;
    esac
}

judge_gh() {
    local -a words=("$@")
    [ "${words[0]:-}" = pr ] || return 0
    case "${words[1]:-}" in
        ready)
            for word in "${words[@]:2}"; do
                [ "$word" = --undo ] && return 0
            done
            deny "$pr_ready" ;;
        merge)
            for word in "${words[@]:2}"; do
                [ "$word" = --admin ] && deny "$pr_admin"
            done ;;
    esac
}

in_worktree() {
    [ -n "$cwd" ] || return 1
    local dirs git_dir common_dir
    dirs="$(git -C "$cwd" rev-parse --path-format=absolute --git-dir --git-common-dir 2>/dev/null)" || return 1
    git_dir="${dirs%%$'\n'*}"
    common_dir="${dirs#*$'\n'}"
    [ -n "$git_dir" ] && [ "$git_dir" != "$common_dir" ]
}

judge_compose() {
    local -a words=("$@")
    local i=0
    while [ "$i" -lt "${#words[@]}" ]; do
        case "${words[i]}" in
            -f|--file|-p|--project-name|--env-file|--profile|--project-directory|--ansi|--parallel|-c|--context) i=$((i + 2)) ;;
            -*) i=$((i + 1)) ;;
            *) break ;;
        esac
    done
    case "${words[i]:-}" in
        ''|ps|logs|config|top|images|ls|port|version|events) return 0 ;;
    esac
    in_worktree && deny "$compose_in_worktree"
}

judge_docker() {
    local -a words=("$@")
    local i=0
    while [ "$i" -lt "${#words[@]}" ]; do
        case "${words[i]}" in
            -H|--host|-c|--context|--config|-l|--log-level) i=$((i + 2)) ;;
            -*) i=$((i + 1)) ;;
            *) break ;;
        esac
    done
    [ "${words[i]:-}" = compose ] && judge_compose "${words[@]:i+1}"
}

judge_command() {
    local -a words=("$@")
    local i=0
    skip_prefix
    case "${words[i]:-} ${words[i+1]:-}" in
        "just exec") i=$((i + 2)) ;;
        "bin/worktrees/compose-exec.sh "*|"./bin/worktrees/compose-exec.sh "*) i=$((i + 1)) ;;
    esac
    skip_prefix
    if wrapped_command "${words[@]:i}"; then
        judge_text "$wrapped"
        return
    fi

    case "${words[i]:-}" in
        git) judge_git "${words[@]:i+1}" ;;
        gh) judge_gh "${words[@]:i+1}" ;;
        docker) judge_docker "${words[@]:i+1}" ;;
        docker-compose) judge_compose "${words[@]:i+1}" ;;
    esac
}

# The filter drops here-document bodies and quotes before the split, so a
# quoted `git add -A` is data. The quoted text of a shell wrapper is a command.
judge_text "$command"

exit 0
