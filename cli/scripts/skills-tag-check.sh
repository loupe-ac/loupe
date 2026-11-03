#!/bin/sh
# Refuses a release whose version is not greater than the highest tag of the package.
# Usage: skills-tag-check.sh <package> <version>
set -eu

package=$1
version=$2
here=$(dirname "$0")

latest=$(git tag --list "skills/$package/v*" --sort=-v:refname | head -n 1)
[ -n "$latest" ] || exit 0

if ! sh "$here/version-greater.sh" "$version" "${latest##*/v}"; then
	echo "::error::The version $version is not greater than the tag $latest. Bump the version in plugin.json first."
	exit 1
fi
