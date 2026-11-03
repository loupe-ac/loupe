set -euo pipefail
source "$(dirname "$0")/../foreign-lib/repo.sh"
epics="3. Epic preview: served at \`https://preview.example.test/epic-<n>\`, from a checkout of the epic branch. A link form is \`https://preview.example.test/epic-<n>/<path>\`."
make_repo "$epics"
git switch -q -c epic/7
printf 'def unique_words(text):\n    return len(set(text.split()))\n' >> tally/counter.py
git commit -q -am "Start the epic"
git push -q origin epic/7
git fetch -q origin
