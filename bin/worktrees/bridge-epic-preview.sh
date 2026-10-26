#!/usr/bin/env bash
# Bridge `before` command: create or refresh the epic preview at .worktrees/epic-<n>.
# Usage: bridge-epic-preview.sh <epicNumber> <epicId>
# The bridge reads the last stdout line as the worker's folder, so all other output goes to stderr.
# The refresh runs under the lock that bridge-teardown.sh takes, so a teardown never removes the folder mid-refresh.
set -euo pipefail

exec 3>&1 1>&2

number=${1:-}
epic_id=${2:-}

if ! [[ $number =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-epic-preview: the epic number '$number' is not a positive integer." >&2
    exit 1
fi
# The recipe interpolates the marker into a shell line unquoted.
if ! [[ $epic_id =~ ^[A-Za-z0-9-]+$ ]]; then
    echo "bridge-epic-preview: the epic id '$epic_id' is empty or has characters other than letters, digits and '-'." >&2
    exit 1
fi

worktrees=$(git worktree list --porcelain)
main=$(awk '/^worktree /{print $2; exit}' <<<"$worktrees")
name="epic-$number"
root="$main/.worktrees/$name"
lock="$main/.worktrees/$name.lock"
branch="epic/$number"

mkdir -p "$main/.worktrees"
locked=0
for _ in $(seq 60); do
    if mkdir "$lock" 2>/dev/null; then
        locked=1
        break
    fi
    sleep 10
done
if [ "$locked" -eq 0 ]; then
    echo "bridge-epic-preview: $lock stayed for 10 minutes. Remove the lock and run again." >&2
    exit 1
fi
# The worker after this script needs no lock: the bridge runs one job per card, so the epic's teardown waits for it.
trap 'rmdir "$lock"' EXIT

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
if ! git -C "$main" show-ref --verify --quiet "refs/remotes/origin/$branch"; then
    echo "bridge-epic-preview: origin/$branch does not exist." >&2
    exit 1
fi
# A tree deleted from disk stays registered until a prune.
git -C "$main" worktree prune --expire now

registered=$(git -C "$main" worktree list --porcelain)
if grep -qxF "worktree $root" <<<"$registered"; then
    git -C "$root" checkout --detach "origin/$branch"
elif [ -e "$root" ]; then
    echo "bridge-epic-preview: $root exists but is not a registered worktree. Remove it and retry." >&2
    exit 1
else
    git -C "$main" worktree add --detach "$root" "origin/$branch"
fi

# From main: bootstrap's bare `docker compose` calls resolve their file from the cwd.
if ! (cd "$main" && just worktree-up "$name" "card:$epic_id"); then
    echo "bridge-epic-preview: could not provision $name (reason above)." >&2
    exit 1
fi
(cd "$root" && bin/worktrees/compose-exec.sh bin/console cache:clear)
(cd "$root" && bin/worktrees/compose-exec.sh bin/console cache:clear --env=test)

printf '%s\n' "$root" >&3
