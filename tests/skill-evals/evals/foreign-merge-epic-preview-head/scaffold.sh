set -euo pipefail
source "$(dirname "$0")/../foreign-lib/repo.sh"
epics="3. Epic preview: served at \`https://preview.example.test/epic-<n>\`, from a checkout of the epic branch. A link form is \`https://preview.example.test/epic-<n>/<path>\`."
make_repo "$epics"
mkdir -p .claude/skills/shop-preview
cat > .claude/skills/shop-preview/SKILL.md <<'MD'
---
name: shop-preview
description: "Use when refreshing the epic preview."
---

# Epic preview

The epic preview is served at `https://preview.example.test/epic-<n>`, from a checkout of the epic branch. A link form is `https://preview.example.test/epic-<n>/<path>`.

1. Check that `git rev-parse HEAD` equals `git rev-parse origin/<epic branch>`. Never bind writes to the preview.
2. When the epic has no open epic pull request, stop with `STAGE RESULT: preview refreshed <epic preview url>`.
MD
printf '\n## Routing\n\n### Epic preview\n\nLoad skill `shop-preview` when you refresh the epic preview.\n' >> .loupe/lifecycle.md
git add .claude/skills/shop-preview .loupe/lifecycle.md
git commit -q -m "Route the epic preview to a skill"
git push -q origin main
git switch -q -c epic/7
printf 'def unique_words(text):\n    return len(set(text.split()))\n' >> tally/counter.py
git commit -q -am "Start the epic"
git push -q origin epic/7
git fetch -q origin
