#!/usr/bin/env bash
# Saves the tools/list answer of the instance that `loupe login` signed in to, so each
# mocked tool carries its real description and input schema. Arguments go to `loupe mcp`.
set -euo pipefail

out="$(cd "$(dirname "$0")" && pwd)/evals/mocks/loupe/_tools.json"
init='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"skill-eval","version":"0"}}}'
ready='{"jsonrpc":"2.0","method":"notifications/initialized"}'
list='{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

# loupe mcp drops the answers still in flight when stdin closes, so a FIFO keeps it open.
dir=$(mktemp -d)
trap 'exec 3>&-; rm -rf "$dir"' EXIT
mkfifo "$dir/in"
loupe mcp "$@" <"$dir/in" >"$dir/out" &
exec 3>"$dir/in"
printf '%s\n' "$init" "$ready" "$list" >&3

answer=
for _ in $(seq 60); do
    answer=$(jq -c 'select(.id == 2)' "$dir/out" 2>/dev/null || true)
    [ -n "$answer" ] && break
    sleep 1
done
[ -n "$answer" ] || { echo "update-tools: no tools/list answer from loupe mcp" >&2; exit 1; }
jq '{tools: (.result.tools | map({name, description, inputSchema}) | sort_by(.name))}' <<<"$answer" > "$out"
echo "update-tools: wrote $(jq '.tools | length' "$out") tools to $out"
