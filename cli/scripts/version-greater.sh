#!/bin/sh
# Exits 0 when the first X.Y.Z version is greater than the second, 1 when it is not, and 2 for any other form.
# Usage: version-greater.sh <version> <other version>
set -eu

for version in "$1" "$2"; do
	if ! printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
		echo "::error::'$version' is not a version such as 1.2.3."
		exit 2
	fi
done

IFS=.
# shellcheck disable=SC2086
set -- $1 $2

for _ in 1 2 3; do
	[ "$1" -gt "$4" ] && exit 0
	[ "$1" -lt "$4" ] && exit 1
	shift
done

exit 1
