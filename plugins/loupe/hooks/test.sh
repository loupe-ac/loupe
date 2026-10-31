#!/bin/sh
# Runs session-end.sh against a fake loupe. It needs no Loupe install.
set -u

pkg=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/bare"
fail=0

check() {
    if [ "$2" != "$3" ]; then
        echo "FAIL $1: got '$2', want '$3'"
        fail=1
    else
        echo "ok   $1"
    fi
}

cat >"$work/bin/loupe" <<'FAKE'
#!/bin/sh
PATH=/usr/bin:/bin
sleep 5
echo "$*" >"$OUT/args"
cat >"$OUT/stdin"
FAKE
chmod +x "$work/bin/loupe"
export OUT="$work"

for tool in cat nohup; do ln -s "$(command -v "$tool")" "$work/bare/$tool"; done

input='{"session_id":"abc-123","hook_event_name":"SessionEnd"}'

start=$(date +%s)
printf '%s' "$input" | PATH="$work/bin:$work/bare" /bin/sh "$pkg/session-end.sh"
check "exit status with loupe" "$?" "0"
elapsed=$(($(date +%s) - start))
[ "$elapsed" -lt 3 ] && speed=fast || speed="${elapsed}s"
check "returns before the child ends" "$speed" "fast"
check "the child has not finished yet" "$([ -f "$work/stdin" ] && echo done || echo waiting)" "waiting"

tries=0
while [ ! -s "$work/stdin" ] && [ "$tries" -lt 100 ]; do
    sleep 0.2
    tries=$((tries + 1))
done
check "the child gets the hook input" "$(cat "$work/stdin" 2>/dev/null)" "$input"
check "the child runs session end" "$(cat "$work/args" 2>/dev/null)" "session end"

printf '%s' "$input" | PATH="$work/bare" /bin/sh "$pkg/session-end.sh"
check "exit status without loupe" "$?" "0"

grep -q '"command": "${CLAUDE_PLUGIN_ROOT}/hooks/session-end.sh"' "$pkg/hooks.json"
check "hooks.json names the script" "$?" "0"

exit "$fail"
