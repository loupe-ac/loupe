#!/usr/bin/env bash
# Installs the Mermaid ES module build into public/vendor/mermaid/<version>/.
# package-lock.json pins the tarball hashes and `npm ci` checks them, so the
# files are the ones the lock names. The install runs in a scratch folder, so a
# worktree whose node_modules links to another checkout is never touched.
#
# Usage: bin/vendor-mermaid.sh [output root, default public/vendor/mermaid]
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-$root/public/vendor/mermaid}
version=$(node -p "require('$root/package.json').dependencies.mermaid")
target="$out/$version"

if [ -f "$target/mermaid.esm.min.mjs" ] && [ -f "$target/THIRD-PARTY.md" ]; then
    exit 0
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cp "$root/package.json" "$root/package-lock.json" "$work/"
(cd "$work" && npm ci --omit=dev --ignore-scripts --no-audit --no-fund --loglevel=error)

dist="$work/node_modules/mermaid/dist"
rm -rf "$out"
mkdir -p "$target/chunks/mermaid.esm.min"
cp "$dist/mermaid.esm.min.mjs" "$target/"
cp "$dist"/chunks/mermaid.esm.min/*.mjs "$target/chunks/mermaid.esm.min/"
cp "$work/node_modules/mermaid/LICENSE" "$target/LICENSE"

(cd "$work" && node -e '
const fs = require("fs");
const lines = ["# Third-party packages", "",
  "Mermaid " + process.argv[1] + " and the packages that its ES module build can bundle.",
  "The licence text of each package is in its npm tarball.", ""];
const names = fs.readdirSync("node_modules").flatMap((n) =>
  n.startsWith("@") ? fs.readdirSync("node_modules/" + n).map((m) => n + "/" + m) : [n]);
for (const name of names.filter((n) => !n.startsWith("."))) {
  const dir = "node_modules/" + name;
  if (!fs.existsSync(dir + "/package.json")) continue;
  const p = JSON.parse(fs.readFileSync(dir + "/package.json", "utf8"));
  let licence = typeof p.license === "string" ? p.license : p.license && p.license.type;
  if (!licence) {
    const f = fs.readdirSync(dir).find((x) => /^licen[cs]e/i.test(x));
    licence = f ? "see its " + f + " file" : "not stated";
  }
  lines.push("- " + name + " " + p.version + ": " + licence);
}
fs.writeFileSync(process.argv[2], lines.join("\n") + "\n");
' "$version" "$target/THIRD-PARTY.md")
