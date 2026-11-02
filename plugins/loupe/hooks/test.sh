#!/bin/sh
# Runs session-end.sh and session-remind.sh against a fake loupe. It needs no Loupe install.
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

mkdir -p "$work/remind"
cat >"$work/remind/loupe" <<'FAKE'
#!/bin/sh
echo "$*" >"$OUT/remind-args"
cat >"$OUT/remind-stdin"
echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"hi"}}'
FAKE
chmod +x "$work/remind/loupe"
for tool in cat; do ln -sf "$(command -v "$tool")" "$work/remind/$tool"; done
remind_input='{"session_id":"abc-123","tool_name":"Edit"}'
want_out='{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"hi"}}'

out=$(printf '%s' "$remind_input" | env -u LOUPE_SESSION_ID PATH="$work/remind" /bin/sh "$pkg/session-remind.sh")
check "remind exit status" "$?" "0"
check "remind passes the output out" "$out" "$want_out"
check "remind passes the hook input in" "$(cat "$work/remind-stdin")" "$remind_input"
check "remind runs session remind" "$(cat "$work/remind-args")" "session remind"

rm -f "$work/remind-args"
out=$(printf '%s' "$remind_input" | LOUPE_SESSION_ID=run-1 PATH="$work/remind" /bin/sh "$pkg/session-remind.sh")
check "remind exit status in a bridge worker" "$?" "0"
check "remind is silent in a bridge worker" "$out" ""
check "remind does not run loupe in a bridge worker" "$([ -f "$work/remind-args" ] && echo ran || echo idle)" "idle"

out=$(printf '%s' "$remind_input" | env -u LOUPE_SESSION_ID PATH="$work/bare" /bin/sh "$pkg/session-remind.sh")
check "remind exit status without loupe" "$?" "0"
check "remind is silent without loupe" "$out" ""

grep -q '"command": "${CLAUDE_PLUGIN_ROOT}/hooks/session-remind.sh"' "$pkg/hooks.json"
check "hooks.json names the remind script" "$?" "0"
check "hooks.json has the Edit and Write matcher" "$(grep -c '"matcher": "Edit|Write"' "$pkg/hooks.json")" "1"
check "hooks.json has the Bash matcher" "$(grep -c '"matcher": "Bash"' "$pkg/hooks.json")" "1"
check "hooks.json limits the Bash entry" "$(grep -c '"if": "Bash(gh pr create\*)"' "$pkg/hooks.json")" "1"
check "hooks.json sets the remind timeout" "$(grep -c '"timeout": 3' "$pkg/hooks.json")" "2"

exit "$fail"
