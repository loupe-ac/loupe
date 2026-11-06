#!/usr/bin/env bash
# Runs github-approval-covers.sh against a fake gh, one fixture per case. It needs bash, jq and git, and no network.
set -u

root=$(cd "$(dirname "$0")/../.." && pwd)
script=$root/plugins/loupe-lifecycle/skills/loupe-stage-implementation/references/forges/github-approval-covers.sh
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin"
fail=0
sha=0123456789abcdef0123456789abcdef01234567
url=https://github.com/acme/app/pull/7

cat >"$work/bin/gh" <<'FAKE'
#!/usr/bin/env bash
case "$*" in
  "auth status"*) exit 0 ;;
  "pr view"*) cat "$FIX/view.json" ;;
  *"/pulls/7/commits"*|*"/pulls/7/reviews"*) echo '[]' ;;
  *"/rules/branches/"*)
    if [ -f "$FIX/rules.err" ]; then cat "$FIX/rules.err" >&2; exit 1; fi
    cat "$FIX/rules.json" ;;
  *"/check-runs"*) cat "$FIX/runs.json" ;;
  *) echo "fake gh: unexpected call: $*" >&2; exit 9 ;;
esac
FAKE
chmod +x "$work/bin/gh"

# fixture <name> <reviewDecision> <rules json, or ERR:<stderr>> <app id of the success run>
fixture() {
  local dir=$work/$1
  mkdir -p "$dir"
  printf '{"headRefOid":"%s","headRefName":"feature","baseRefName":"main","reviewDecision":"%s"}' "$sha" "$2" >"$dir/view.json"
  case "$3" in
    ERR:*) printf '%s\n' "${3#ERR:}" >"$dir/rules.err" ;;
    *) printf '%s' "$3" >"$dir/rules.json" ;;
  esac
  printf '{"check_runs":[{"app":{"id":%s,"slug":"app-%s"},"conclusion":"success","head_sha":"%s"}]}' "$4" "$4" "$sha" >"$dir/runs.json"
}

# expect <name> <exit code> <output prefix> <script args...>
expect() {
  local name=$1 code=$2 prefix=$3
  shift 3
  local out got
  out=$(FIX=$work/$name PATH="$work/bin:$PATH" bash "$script" "$@" 2>&1)
  got=$?
  if [ "$got" != "$code" ] || [ "${out#"$prefix"}" = "$out" ]; then
    echo "FAIL $name: exit $got, output '$out'; want exit $code, output starting '$prefix'"
    fail=1
  else
    echo "ok   $name"
  fi
}

named='[{"type":"required_status_checks","parameters":{"required_status_checks":[{"context":"Loupe / Approval","integration_id":42}]}}]'

fixture plan-refusal APPROVED 'ERR:gh: Upgrade to GitHub Pro or make this repository public to enable this feature. (HTTP 403)' 42
expect plan-refusal 0 'COVERED check=app-42' --app-id 42 "$url" "$sha"

fixture other-403 APPROVED 'ERR:gh: Resource not accessible by integration (HTTP 403)' 42
expect other-403 2 'UNREAD: rules read failed' --app-id 42 "$url" "$sha"

fixture wrong-app APPROVED '[]' 99
expect wrong-app 1 'HOLD no approval check on the head' --app-id 42 "$url" "$sha"

fixture missing-app-id APPROVED '[]' 42
expect missing-app-id 2 'UNREAD: no rule names Loupe / Approval and --app-id is missing' "$url" "$sha"

fixture review-required REVIEW_REQUIRED "$named" 42
expect review-required 1 'HOLD GitHub requires more approvals' "$url" "$sha"

fixture named-check APPROVED "$named" 42
expect named-check 0 'COVERED check=app-42' "$url" "$sha"

fixture named-check-other-app APPROVED "$named" 99
expect named-check-other-app 1 'HOLD no approval check on the head' --app-id 99 "$url" "$sha"

exit $fail
