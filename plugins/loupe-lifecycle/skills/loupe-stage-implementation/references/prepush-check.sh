#!/usr/bin/env bash
# Finds the Preview and Prototype map gaps that the text of a pull request can show before the push.
# Usage: prepush-check.sh --profile <file> --body <file> --notes <file> --base <ref> --card-type <type> --prototype yes|no
# Run it from the worker folder. It makes no network call and opens no link.
# --profile is .loupe/lifecycle.md, --body is the pull request body file, --notes is the build notes saved from document_get.
# --base is the base ref of the pull request. --prototype is yes when the card, its parent, its source card or a linked design links an artifact.
# Prints one line for each gap: "gap: <text>" or "not-proven: <text>". Prints "clean" when there is none.
# Exits 0 when clean, 1 when a gap needs a fix, 2 when the only gaps are "Not proven" lines.
set -o pipefail
usage="usage: prepush-check.sh --profile <file> --body <file> --notes <file> --base <ref> --card-type <type> --prototype yes|no"
profile= body= notes= base= card_type= prototype=
while [ $# -gt 0 ]; do
  case $1 in
    --profile) profile=${2:-}; shift 2 ;;
    --body) body=${2:-}; shift 2 ;;
    --notes) notes=${2:-}; shift 2 ;;
    --base) base=${2:-}; shift 2 ;;
    --card-type) card_type=${2:-}; shift 2 ;;
    --prototype) prototype=${2:-}; shift 2 ;;
    *) echo "$usage" >&2; exit 64 ;;
  esac
done
for v in profile body notes base card_type prototype; do
  [ -n "${!v}" ] || { echo "$usage" >&2; exit 64; }
done
[ "$prototype" = yes ] || [ "$prototype" = no ] || { echo "$usage" >&2; exit 64; }
for f in "$profile" "$body" "$notes"; do
  [ -r "$f" ] || { echo "cannot read $f" >&2; exit 64; }
done

gaps=() unproven=()

# section <file> <heading line>: the lines under the heading, up to the next heading of the same or a higher level.
section() {
  awk -v want="$2" '
    function level(s,   n) { n = 0; while (substr(s, n + 1, 1) == "#") n++; return n }
    BEGIN { wl = level(want) }
    inside && /^#+ / && level($0) <= wl { exit }
    inside { print }
    $0 == want { inside = 1 }
  ' "$1"
}

# fenced <text>: the lines of the first fenced block, with their indent removed.
fenced() {
  awk '
    /^[[:space:]]*```/ { if (open) exit; open = 1; next }
    open { sub(/^[[:space:]]+/, ""); if ($0 != "") print }
  '
}

preview_profile=$(section "$profile" "## Preview")

# The Paths item names the folders in its first sentence. The Excluded paths item names regexes for paths that do not count.
applies=no
if [ -n "$preview_profile" ] && [ "$card_type" != epic ]; then
  paths_line=$(grep -m1 -E '^[0-9]+\. Paths:' <<<"$preview_profile")
  first_sentence=$(sed -E 's/^[0-9]+\. Paths: //; s/\. .*$//' <<<"$paths_line")
  prefixes=$(grep -oE '`[^`]+/`' <<<"$first_sentence" | tr -d '`')
  excluded=$(grep -m1 -E '^[0-9]+\. Excluded paths:' <<<"$preview_profile" | grep -oE '`[^`]+`' | tr -d '`')
  mb=$(git merge-base "$base" HEAD 2>/dev/null) || mb=$base
  changed=$( { git diff --name-only "$mb" 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null; } | sort -u)
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    hit=no
    while IFS= read -r prefix; do
      [ -n "$prefix" ] || continue
      case $path in "$prefix"*) hit=yes ;; esac
    done <<<"$prefixes"
    [ $hit = yes ] || continue
    while IFS= read -r pattern; do
      [ -n "$pattern" ] || continue
      if grep -Eq -- "$pattern" <<<"$path"; then hit=no; fi
    done <<<"$excluded"
    if [ $hit = yes ]; then applies=yes; break; fi
  done <<<"$changed"
fi

if [ $applies = yes ]; then
  heading=$(grep -m1 -E '^[0-9]+\. Section:' <<<"$preview_profile" | grep -oE '`[^`]+`' | head -n1 | tr -d '`')
  heading=${heading:-## Preview}
  patterns=$(awk '/Line patterns:/ { go = 1 } go' <<<"$preview_profile" | fenced)
  body_section=$(section "$body" "$heading")
  lines=$(grep -E '^- ' <<<"$body_section")
  if ! grep -qxF -- "$heading" "$body"; then
    gaps+=("the pull request body has no \"$heading\" section")
  elif [ -z "$lines" ]; then
    gaps+=("the \"$heading\" section of the body has no list line")
  fi
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    if grep -qi 'not proven' <<<"$line"; then
      unproven+=("$line")
      continue
    fi
    if [ -n "$patterns" ]; then
      ok=no
      while IFS= read -r pattern; do
        if grep -Eq -- "$pattern" <<<"$line"; then ok=yes; break; fi
      done <<<"$patterns"
      [ $ok = yes ] || gaps+=("Preview line fits no line pattern of the profile (link, marker, and the empty path or no data): $line")
    else
      grep -qE '\[[^]]+\]\([^)]+\)' <<<"$line" || gaps+=("Preview line has no Markdown link: $line")
      grep -qE '`[^`]+`' <<<"$line" || gaps+=("Preview line has no marker in backticks: $line")
    fi
  done <<<"$lines"

  # Each state of the Preview list in the build notes needs a line in the body.
  notes_lines=$(section "$notes" "## Preview" | grep -E '^- ')
  while IFS= read -r nl; do
    [ -n "$nl" ] || continue
    grep -qi 'not proven' <<<"$nl" && continue
    item=${nl#- }
    if [[ $item =~ ^\[([^]]+)\] ]]; then label=${BASH_REMATCH[1]}
    else label=$(sed -E 's/[`:,].*$//; s/ [-—] .*$//; s/[[:space:]]+$//' <<<"$item"); fi
    [ -n "$label" ] || continue
    grep -qF -- "[$label]" <<<"$body_section" || gaps+=("the build notes Preview list names \"$label\", and the body has no line for it")
  done <<<"$notes_lines"
fi

if [ "$prototype" = yes ]; then
  map=$(section "$notes" "## Prototype map" | grep -v '^[[:space:]]*$')
  [ -n "$map" ] || gaps+=("the card links a prototype, and the build notes have no \"Prototype map\" section with content")
fi

for g in "${gaps[@]}"; do echo "gap: $g"; done
for u in "${unproven[@]}"; do echo "not-proven: $u"; done
if [ ${#gaps[@]} -gt 0 ]; then exit 1; fi
if [ ${#unproven[@]} -gt 0 ]; then exit 2; fi
echo clean
