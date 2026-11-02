set -euo pipefail
source "$(dirname "$0")/../foreign-lib/repo.sh"
make_repo
git switch -q -c card-42-longest-word
cat >> tally/counter.py <<'PY'


def longest_word(text):
    """Return the longest word of text, or an empty string."""
    return max(text.split(), key=len, default="")
PY
python3 - <<'PY'
p = "tests/test_counter.py"
s = open(p).read()
s = s.replace("from tally.counter import count_words", "from tally.counter import count_words, longest_word")
s = s.replace('\n\nif __name__', '''
    def test_longest_word(self):
        self.assertEqual(longest_word("a bbb cc"), "bbb")


if __name__''', 1)
open(p, "w").write(s)
PY
git commit -q -am "Add longest_word"
git push -q -u origin card-42-longest-word
git switch -q main
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
git commit -q -am "Add unique_words"
git push -q origin main
git switch -q card-42-longest-word
git fetch -q origin
