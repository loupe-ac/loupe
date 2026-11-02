set -euo pipefail
source "$(dirname "$0")/../foreign-lib/repo.sh"
make_repo
git switch -q --detach origin/main
cat >> tally/counter.py <<'PY'


def unique_words(text):
    """Return the number of distinct words in text."""
    return len(set(text.split()))
PY
python3 - <<'PY'
p = "tests/test_counter.py"
s = open(p).read()
s = s.replace("from tally.counter import count_words", "from tally.counter import count_words, unique_words")
s = s.replace('\n\nif __name__', '''
    def test_unique_words_ignores_repeats(self):
        self.assertEqual(unique_words("a b a"), 2)


if __name__''', 1)
open(p, "w").write(s)
PY
