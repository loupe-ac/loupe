set -euo pipefail
source "$(dirname "$0")/../foreign-lib/repo.sh"
make_repo
git switch -q -c card-42-average-length
cat >> tally/counter.py <<'PY'


def average_word_length(text):
    """Return the mean number of letters in a word of text."""
    words = text.split()
    return sum(len(w) for w in words) / len(words)
PY
git commit -q -am "Add average_word_length"
git push -q -u origin card-42-average-length
git switch -q main
git fetch -q origin
