#!/usr/bin/env bash
#
# Logs what a hung coverage shard waits for. Every <seconds> it appends the PHP
# processes of the php-fpm container and the busy or lock-waiting database
# sessions to <logfile>. The weekly workflow uploads that file with the shard.
#
# Usage: bin/coverage-watchdog.sh <logfile> [seconds]
set -u

log=${1:?usage: coverage-watchdog.sh <logfile> [seconds]}
every=${2:-300}

mkdir -p "$(dirname "$log")"
while true; do
    sleep "$every"
    {
        echo "== $(date -u +%H:%M:%S)"
        bin/worktrees/compose-exec.sh sh -c 'ps -eo pid,etime,time,stat,args | grep -E "[p]hp" | cut -c1-220; php bin/coverage-activity.php'
    } >>"$log" 2>&1
done
