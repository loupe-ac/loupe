# Builds "tally", a small Python repository that is not Loupe. It has no justfile, no
# Codex, no changelog and no epic preview. A foreign-* scaffold script sources this file.
# The remote is a bare repository under .remote, so fetch, merge and push work offline.
set -euo pipefail

export GIT_AUTHOR_NAME=eval GIT_AUTHOR_EMAIL=eval@example.test
export GIT_COMMITTER_NAME=eval GIT_COMMITTER_EMAIL=eval@example.test
export GIT_CONFIG_GLOBAL=/dev/null

# make_repo [epics-line]: build the repository with a profile and the given Epics line. NO_PROFILE=1 builds it with no profile.
make_repo() {
    git init -q -b main .
    mkdir -p tally tests .loupe
    : > tally/__init__.py
    cat > tally/counter.py <<'PY'
def count_words(text):
    """Return the number of words in text."""
    return len(text.split())
PY
    cat > tests/test_counter.py <<'PY'
import unittest

from tally.counter import count_words


class CountWordsTest(unittest.TestCase):
    def test_counts_words(self):
        self.assertEqual(count_words("one two three"), 3)

    def test_empty_text_has_no_words(self):
        self.assertEqual(count_words(""), 0)


if __name__ == "__main__":
    unittest.main()
PY
    cat > README.md <<'MD'
# tally

A tiny word counting library.
MD
    cat > CONTRIBUTING.md <<'MD'
# Contributing

Run the tests with `python3 -m unittest discover -s tests`.
Keep each function short. Write a test for each change.
MD
    cat > pyproject.toml <<'TOML'
[project]
name = "tally"
version = "0.1.0"
TOML
    if [ -z "${NO_PROFILE:-}" ]; then write_profile "${1:-}"; fi
    printf '.remote/\n__pycache__/\n' > .gitignore
    git add -A
    git commit -q -m "Start tally"
    git init -q --bare .remote/origin.git
    git remote add origin "$PWD/.remote/origin.git"
    git push -q origin main
    git fetch -q origin
    use_real_git
}

# On macOS, /usr/bin/git can be a shim that fails in the sandbox of a run, because it cannot
# write its cache. The wrapper .bin/git runs the real binary. A prompt tells the run to use
# ./.bin/git in place of git, and the recipe grants that command.
use_real_git() {
    local real=""
    for candidate in /Library/Developer/CommandLineTools/usr/bin/git /opt/homebrew/bin/git /usr/local/bin/git; do
        if [ -x "$candidate" ]; then real=$candidate; break; fi
    done
    if [ -z "$real" ]; then real=$(command -v git); fi
    mkdir -p .bin
    printf '#!/bin/sh\nexec %s "$@"\n' "$real" > .bin/git
    chmod +x .bin/git
    printf '.bin/\n' >> .git/info/exclude
}

# write_profile [epics-line]: the profile of a repository with no Changelog and no Preview.
write_profile() {
    local epics_line="${1:-3. Epic preview: none (owner to fill in). With none, a refresh of the epic preview finishes with no change. Name the preview, its link form and the shape of a value from a pull request body here when this repository serves an epic branch.}"
    cat > .loupe/lifecycle.md <<MD
# Lifecycle profile

The Loupe stage skills read this file. It holds the values that belong to this repository.

## Instruction files

1. Read \`CONTRIBUTING.md\`, and follow it.

## Forge

1. github

## Environment

1. Folder check: none.

## Gate

1. Base branch: \`main\`.
2. Run \`python3 -m unittest discover -s tests\`.
3. Required checks: \`unit\`.

## Code review

1. Review before the push: none.

## Pull request

1. Open it as a draft, against the base branch of the \`Gate\` section, and never mark it ready.
2. Write the title as a one-line summary of the change. Name the branch \`card-<number>-<short-slug>\`.

## Board

1. The column that holds a pull request waiting for review is \`in-review\`.
2. The column that holds a card in product design is \`product-design\`.
3. The column that holds a card in tech design is \`tech-design\`.
4. The column that holds a card in implementation is \`implementation\`.
5. The default column is \`backlog\`, and the terminal column is \`done\`.

## Merge

1. The merge method: squash.
2. The base branch is the base branch of the \`Gate\` section.
3. This profile names no approver.
4. An epic child merges as the \`Epics\` section says.

## Epics

1. The epic branch of epic card \`<n>\` is \`epic/<n>\`. It must match the \`epicBranch\` value that \`workflow_get\` returns.
2. The merge method of an epic child: squash.
${epics_line}

## Repair

1. Before each action, \`git status --porcelain\` must print nothing. Otherwise stop blocked.
2. Remove a broken worker folder with \`loupe env reset\`, only when the \`repair\` work entry of the rule file sets \`reset\`.
3. Sync the epic branch of the parent card, as \`loupe-stage-merge\` "Sync the epic branch" says, with the forge adapter it names.
4. Other actions: owner to fill in. The worker takes no action that this list does not name.
MD
}
