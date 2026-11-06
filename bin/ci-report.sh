#!/usr/bin/env bash
# Downloads a report artifact from GitHub Actions into var/ci-reports/ and
# prints its summary. With no run id it takes the newest run on main that still
# holds the artifact, because a red run can hold one and a green run can lack it.
#
# Usage: bin/ci-report.sh <mutation|phpunit-coverage|e2e-coverage|e2e-timing|phpunit-timing> [run-id]
set -euo pipefail

report=${1:-}
run=${2:-}
# A glob rather than a name, for a report the e2e shards upload one of each.
# `gh run download --pattern` puts every match in its own subdirectory, so a
# pattern also changes the layout under $dir.
pattern=''

case "$report" in
    mutation) workflow='Coverage report' artifact=infection-report ;;
    phpunit-coverage) workflow='Coverage report' artifact=phpunit-coverage ;;
    e2e-coverage) workflow='Coverage report' artifact=e2e-coverage ;;
    # The trailing `*` also matches `e2e-timing`, the single artifact a run
    # from before the e2e job was sharded holds.
    e2e-timing) workflow='CI' artifact=e2e-timing pattern='e2e-timing*' ;;
    phpunit-timing) workflow='CI' artifact=phpunit-timing ;;
    *)
        echo "usage: $0 <mutation|phpunit-coverage|e2e-coverage|e2e-timing|phpunit-timing> [run-id]" >&2
        exit 2
        ;;
esac

repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)

holds_artifact() {
    local name
    for name in $(gh api "repos/$repo/actions/runs/$1/artifacts" \
        -q '.artifacts[] | select(.expired | not) | .name'); do
        # shellcheck disable=SC2053
        [[ $name == ${pattern:-$artifact} ]] && return 0
    done

    return 1
}

if [ -z "$run" ]; then
    for id in $(gh run list --repo "$repo" --workflow "$workflow" --branch main \
        --status completed --limit 20 --json databaseId -q '.[].databaseId'); do
        if holds_artifact "$id"; then
            run=$id
            break
        fi
    done
    if [ -z "$run" ]; then
        echo "No completed '$workflow' run in the last 20 on main holds '${pattern:-$artifact}'." >&2
        exit 1
    fi
fi

# The date and conclusion of the chosen run, then a warning for each newer
# completed run on main that failed. The warning goes to stderr and the exit
# status stays 0, so a reader still gets the older report.
run_json=$(gh api "repos/$repo/actions/runs/$run" -q '{c: .conclusion, d: .created_at, w: .name}')
run_date=$(jq -r '.d[0:10]' <<<"$run_json")
run_conclusion=$(jq -r '.c' <<<"$run_json")
echo "run $run: $run_date, $run_conclusion"
newer=$(gh run list --repo "$repo" --workflow "$workflow" --branch main --status completed \
    --limit 20 --json databaseId,conclusion,createdAt \
    -q "[.[] | select(.databaseId > $run and .conclusion != \"success\")] | sort_by(.createdAt)")
newer_count=$(jq 'length' <<<"$newer")
if [ "$newer_count" -gt 0 ]; then
    newest=$(jq -r 'last.createdAt[0:10]' <<<"$newer")
    echo "warning: $newer_count newer '$workflow' runs failed, newest $newest; this report is from $run_date" >&2
fi
if [ "$run_conclusion" != success ]; then
    echo "warning: run $run ended $run_conclusion; its report may be incomplete" >&2
fi

dir="var/ci-reports/$artifact/$run"
if [ ! -d "$dir" ]; then
    rm -rf "$dir.part"
    mkdir -p "$(dirname "$dir")"
    if [ -n "$pattern" ]; then
        gh run download "$run" --repo "$repo" --pattern "$pattern" --dir "$dir.part"
    else
        gh run download "$run" --repo "$repo" --name "$artifact" --dir "$dir.part"
    fi
    mv "$dir.part" "$dir"
fi

echo "https://github.com/$repo/actions/runs/$run"
echo "files: $dir"
echo

case "$report" in
    mutation) cat "$dir/summary.log" ;;
    phpunit-coverage | e2e-coverage) head -12 "$dir/summary.txt" ;;
    # A sharded run holds one report per shard, each in its own directory. A
    # run downloaded before the split kept its one report at the top of $dir,
    # and that cache is not re-downloaded, so both layouts have to read.
    e2e-timing) find "$dir" -name results.json | sort | xargs node bin/e2e-timing.mjs ;;
    phpunit-timing) php bin/phpunit-timing.php "$dir/junit.xml" ;;
esac
