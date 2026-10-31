#!/usr/bin/env bash
# Bridge `before` command: create or repair the card's worktree at .worktrees/card-<n>.
# Usage: bridge-before.sh [--git-only] [--epic-branch=<name>] <cardNumber> <cardId> [<pullRequestNumber>]
# --git-only makes the git worktree alone, with no database, containers or widget marker.
# --epic-branch cuts a new worktree from origin/<name> rather than origin/main. An empty name means none.
# The bridge reads the last stdout line as the worker's folder, so all other output goes to stderr.
set -euo pipefail

exec 3>&1 1>&2

git_only=""
epic_branch=""
while [ $# -gt 0 ]; do
    case $1 in
        --git-only) git_only=1 ;;
        --epic-branch=*) epic_branch=${1#--epic-branch=} ;;
        *) break ;;
    esac
    shift
done

number=${1:-}
card_id=${2:-}
pr=${3:-}

if ! [[ $number =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-before: the card number '$number' is not a positive integer." >&2
    exit 1
fi
# The recipe interpolates the marker into a shell line unquoted.
if ! [[ $card_id =~ ^[A-Za-z0-9-]+$ ]]; then
    echo "bridge-before: the card id '$card_id' is empty or has characters other than letters, digits and '-'." >&2
    exit 1
fi
if [ -n "$pr" ] && ! [[ $pr =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-before: the pull request number '$pr' is not a positive integer." >&2
    exit 1
fi

if [ -n "$epic_branch" ] && { [[ $epic_branch == -* ]] || ! git check-ref-format --branch "$epic_branch" >/dev/null 2>&1; }; then
    echo "bridge-before: the epic branch '$epic_branch' is not a valid branch name." >&2
    exit 1
fi

worktrees=$(git worktree list --porcelain)
main=$(awk '/^worktree /{print $2; exit}' <<<"$worktrees")
name="card-$number"
root="$main/.worktrees/$name"

# Two fetches that update one remote ref at once make the slower one exit non-zero.
fetch_origin() {
    local attempt
    for attempt in 1 2 3; do
        git -C "$main" fetch origin "$@" && return 0
        [ "$attempt" -lt 3 ] && sleep "$attempt"
    done
    return 1
}

fetch_origin
# A tree deleted from disk stays registered until a prune, and holds its branch.
git -C "$main" worktree prune --expire now

head=""
if [ -n "$pr" ]; then
    head=$(cd "$main" && gh pr view "$pr" --json headRefName -q .headRefName)
    if [ -z "$head" ]; then
        echo "bridge-before: pull request $pr has no head branch." >&2
        exit 1
    fi
    fetch_origin "$head"
fi

# Git checks a branch out in one worktree only, so a session tree left on the card branch blocks every run.
free_branch() {
    local holder
    holder=$(git -C "$main" worktree list --porcelain \
        | awk -v ref="branch refs/heads/$1" '/^worktree /{path=substr($0, 10)} $0 == ref {print path}')
    { [ -z "$holder" ] || [ "$holder" = "$root" ]; } && return 0
    if [ "$holder" = "$main" ]; then
        echo "bridge-before: the main checkout holds $1. Switch it back to main, then retry." >&2
        exit 1
    fi
    if [ -n "$(git -C "$holder" status --porcelain)" ] \
        || ! git -C "$holder" merge-base --is-ancestor HEAD "origin/$1" 2>/dev/null; then
        echo "bridge-before: $holder holds $1 with uncommitted or unpushed work." >&2
        echo "Commit and push that work in $holder, then retry. 'just worktree-down' would delete its uncommitted files." >&2
        exit 1
    fi
    echo "bridge-before: $holder holds $1 with nothing unpushed; detaching it there." >&2
    git -C "$holder" switch --detach --quiet
}

# The '-' after the number keeps card-4 from matching card-40-*.
branch=$(git -C "$main" for-each-ref --sort=-committerdate --count=1 \
    --format='%(refname:short)' "refs/heads/$name-*")
wanted=${head:-$branch}

registered=$(git -C "$main" worktree list --porcelain)
if grep -qxF "worktree $root" <<<"$registered"; then
    echo "bridge-before: $name already exists; re-provisioning it." >&2
    [ -n "$wanted" ] && free_branch "$wanted"
    # A new head can fix what made the last provisioning fail, so provision that head.
    if [ -n "$head" ] && [ "$(git -C "$root" branch --show-current)" = "$head" ]; then
        git -C "$root" merge --ff-only "origin/$head" \
            || echo "bridge-before: $head did not fast-forward; the worker syncs it." >&2
    fi
elif [ -e "$root" ]; then
    echo "bridge-before: $root exists but is not a registered worktree. Remove it and retry." >&2
    exit 1
else
    if [ -n "$head" ]; then
        free_branch "$head"
        if git -C "$main" show-ref --verify --quiet "refs/heads/$head"; then
            git -C "$main" worktree add "$root" "$head"
        else
            git -C "$main" worktree add --track -b "$head" "$root" "origin/$head"
        fi
    elif [ -n "$branch" ]; then
        free_branch "$branch"
        git -C "$main" worktree add "$root" "$branch"
    elif [ -n "$epic_branch" ]; then
        if ! git -C "$main" show-ref --verify --quiet "refs/remotes/origin/$epic_branch"; then
            echo "bridge-before: the epic branch $epic_branch is missing on origin." >&2
            exit 1
        fi
        git -C "$main" worktree add --detach "$root" "origin/$epic_branch"
    else
        git -C "$main" worktree add --detach "$root" origin/main
    fi
fi

if [ -n "$git_only" ]; then
    printf '%s\n' "$root" >&3
    exit 0
fi

widget_backend() {
    [ -f "$1" ] && grep -E '^SITE_REVIEW_WIDGET_BACKEND=' "$1" | tail -1 | cut -d= -f2- | tr -d '"'"'"
}
# A widget branch points its backend at its own host, where a production card id names no card.
context="card:$card_id"
if [ -f "$root/.env.local" ] && [ "$(widget_backend "$root/.env.local")" != "$(widget_backend "$main/.env.local")" ]; then
    echo "bridge-before: $name has its own widget backend; keeping its card marker." >&2
    context=""
fi

# From main: bootstrap's bare `docker compose` calls resolve their file from the cwd.
if ! (cd "$main" && just worktree-up "$name" $context); then
    echo "bridge-before: could not provision $name (reason above)." >&2
    # The tree stays, so the next run provisions it again and the teardown removes it.
    exit 1
fi

printf '%s\n' "$root" >&3
