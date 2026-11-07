#!/usr/bin/env bash
# Runs github-required-checks.sh against a fake gh, one fixture per case. It needs bash and jq, and no network.
set -u

root=$(cd "$(dirname "$0")/../.." && pwd)
script=$root/plugins/loupe-lifecycle/skills/loupe-stage-implementation/references/forges/github-required-checks.sh
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin"
fail=0
url=https://github.com/acme/app/pull/7

cat >"$work/bin/gh" <<'FAKE'
#!/usr/bin/env bash
case "$*" in
  "auth status"*) exit 0 ;;
  "pr view"*) echo "${FAKE_BASE:-main}" ;;
  *"/rules/branches/"*)
    if [ -f "$FIX/rules.err" ]; then cat "$FIX/rules.err" >&2; exit 1; fi
    cat "$FIX/rules.json" ;;
  *"/branches/"*)
    if [ -f "$FIX/branch.err" ]; then echo "$(cat "$FIX/branch.err")" >&2; exit 1; fi
    cat "$FIX/branch.json" ;;
  *) echo "fake gh: unexpected call: $*" >&2; exit 9 ;;
esac
FAKE
chmod +x "$work/bin/gh"

# fixture <name> <rules json, or ERR:<stderr>> <branch json, or ERR:<stderr>>
fixture() {
  local dir=$work/$1
  mkdir -p "$dir"
  case "$2" in
    ERR:*) printf '%s\n' "${2#ERR:}" >"$dir/rules.err" ;;
    *) printf '%s' "$2" >"$dir/rules.json" ;;
  esac
  case "$3" in
    ERR:*) printf '%s\n' "${3#ERR:}" >"$dir/branch.err" ;;
    *) printf '%s' "$3" >"$dir/branch.json" ;;
  esac
}

# expect <name> <exit code> <exact output> <script args...>
expect() {
  local name=$1 code=$2 want=$3
  shift 3
  local out got
  out=$(FIX=$work/$name PATH="$work/bin:$PATH" bash "$script" "$@" 2>&1)
  got=$?
  if [ "$got" != "$code" ] || [ "$out" != "$want" ]; then
    echo "FAIL $name: exit $got, output '$out'; want exit $code, output '$want'"
    fail=1
  else
    echo "ok   $name"
  fi
}

rules='[{"type":"required_status_checks","parameters":{"required_status_checks":[{"context":"Loupe / Approval","integration_id":42},{"context":"phpunit"},{"context":"lint"}]}}]'
none='{"protection":{"enabled":false,"required_status_checks":{"checks":[],"contexts":[]}}}'
classic='{"protection":{"enabled":true,"required_status_checks":{"contexts":["build","lint"],"checks":[{"context":"build"},{"context":"e2e"}]}}}'
unprotected='{"protection":{"enabled":false}}'
plan='ERR:gh: Upgrade to GitHub Pro or make this repository public to enable this feature. (HTTP 403)'

fixture rulesets-only "$rules" "$none"
expect rulesets-only 0 $'lint\nphpunit' "$url"

fixture classic-only '[]' "$classic"
expect classic-only 0 $'build\ne2e\nlint' "$url"

fixture union "$rules" "$classic"
expect union 0 $'build\ne2e\nlint\nphpunit' "$url"

fixture no-rules '[]' "$unprotected"
expect no-rules 0 '' "$url"

fixture plan-refusal "$plan" "$classic"
expect plan-refusal 0 $'build\ne2e\nlint' "$url"

fixture rules-denied 'ERR:gh: Resource not accessible (HTTP 403)' "$none"
expect rules-denied 2 'UNREAD: rules read failed' "$url"

fixture branch-denied "$rules" 'ERR:gh: Not Found (HTTP 404)'
expect branch-denied 2 'UNREAD: branch read failed' "$url"

fixture base-flag "$rules" "$none"
expect base-flag 0 $'lint\nphpunit' --base release/1 "$url"

expect bad-url 2 'UNREAD: not a GitHub pull request URL: https://example.com/x' https://example.com/x

exit $fail
