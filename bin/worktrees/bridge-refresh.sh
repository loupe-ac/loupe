#!/usr/bin/env bash
# Bridge `refresh` command: bring the card's worktree up to date after a merge brings commits.
# Usage: bridge-refresh.sh <cardNumber> <cardId>
# The bridge runs it in the worker folder when a stage calls `loupe env refresh`.
# <cardId> becomes the card marker, so the site-review widget links each preview comment to the card.
set -euo pipefail

number=${1:-}
card_id=${2:-}

if ! [[ $number =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-refresh: the card number '$number' is not a positive integer." >&2
    exit 1
fi
# The recipe interpolates the marker into a shell line unquoted.
if ! [[ $card_id =~ ^[A-Za-z0-9-]+$ ]]; then
    echo "bridge-refresh: the card id '$card_id' is empty or has characters other than letters, digits and '-'." >&2
    exit 1
fi

main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
name="card-$number"

widget_backend() {
    [ -f "$1" ] && grep -E '^SITE_REVIEW_WIDGET_BACKEND=' "$1" | tail -1 | cut -d= -f2- | tr -d '"'"'"
}
# A widget branch points its backend at its own host, where a production card id names no card.
context="card:$card_id"
if [ -f "$main/.worktrees/$name/.env.local" ] \
    && [ "$(widget_backend "$main/.worktrees/$name/.env.local")" != "$(widget_backend "$main/.env.local")" ]; then
    echo "bridge-refresh: $name has its own widget backend; keeping its card marker." >&2
    context=""
fi

# From main: the recipe's bare `docker compose` calls resolve their file from the cwd.
(cd "$main" && just worktree-up "$name" $context)

folder="$main/.worktrees/$name"
(cd "$folder" && bin/worktrees/compose-exec.sh bin/console cache:clear)
(cd "$folder" && bin/worktrees/compose-exec.sh bin/console cache:clear --env=test)
