# Implementation stage detail

`SKILL.md` names each step. This file holds the detail. The repository profile and the two adapters hold every value that belongs to one repository, one harness or one forge.

## Load the adapters and the profile

1. Load the adapter for your harness from the `harnesses/` folder in this directory. Use `claude-code.md` in Claude Code, `codex.md` in Codex, and `generic.md` in any other harness.
2. Read the repository profile at `.loupe/lifecycle.md` in the repository root. When the file, or a section a step needs, is missing, stop with `STAGE RESULT: blocked: no <section> in .loupe/lifecycle.md`.
3. Pick the forge adapter as the next section says.

The profile has these sections: `Instruction files`, `Environment`, `Gate`, `Code review`, `Changelog`, `Pull request`, `Board`, `Merge` and `Repair`. The profile can also hold these optional sections:

- A `Forge` section with one value, the forge name, such as `github`.
- A `Decision records` section that `loupe-recommendations` reads.
- A `Visual check` section that `visual-check.md` reads.
- A `Preview` section that `preview.md` reads.

A missing optional section blocks nothing. The harness adapter covers these steps:

- Connect to the Loupe tools.
- Load an instruction.
- Run a long command.
- Dispatch a sub-agent.
- Write the build notes.
- Run the build notes task by task.

## Pick the forge adapter

The stage skills name forge operations. An adapter file maps them to commands for one forge. The operations are these:

- Find and validate a pull request.
- List feedback items.
- Reply to a thread.
- Post a top-level comment.
- Post a refusal comment.
- Read checks and failed logs.
- Create a pull request.
- Check mergeability.

The merge stage adds these operations:

- Read the merge state.
- Check the approval covers the head.
- Compare with the base.
- Update the branch.
- Merge.
- Change the base.
- Merge a branch into the epic branch.

1. When the profile has a `Forge` section, its value is the forge. Take no other source.
2. Otherwise read the forge from `pullRequests[].forge` in `card_get`: `github`, `gitlab`, `bitbucket` or `other`. The app stores `other` for every link it cannot parse, so `other` names no forge. Go on to item 3.
3. Otherwise read the host of `git remote get-url origin`. `github.com` is `github`, a `gitlab` host is `gitlab`, `bitbucket.org` is `bitbucket`, and any other host is `other`.
4. Read `forges/<forge>.md` in this directory.
5. When no such file exists, stop with `STAGE RESULT: blocked: no forge adapter for <forge>`. A repository on a self-hosted forge names it in the profile `Forge` section.

### Report words

Each read section of a forge file ends with a Report line. It translates the values of that forge into the words below. A stage skill reads these words only, and never a value that belongs to one forge.

| Word | Values |
|---|---|
| state | `open`, `merged`, `closed` |
| draft | `yes`, `no` |
| review | `approved`, `changes-requested`, `required`, `none` |
| checks | `passed`, `failed`, `pending` |
| mergeability | `mergeable`, `conflicting`, `behind`, `blocked`, `unknown` |
| behind count | a number, from "Compare with the base" |
| approval | `COVERED`, `HOLD <reason>`, `UNREAD: <reason>` |

Every approval script of a forge prints the same three result lines: `COVERED`, `HOLD` and `UNREAD`.

## Card branch

A card branch starts with the prefix `card-<number>-` by default. The profile `Pull request` section can name another pattern. This stage then reads `<prefix>` as that pattern up to its slug, and `<card branch>` as the prefix followed by `<short-slug>`. Every step below that says `card-<number>-` uses `<prefix>`, and every step that creates a branch uses `<card branch>`.

## Column check

Slug a column label from the prompt: lowercase, with hyphens for spaces. Compare the slug with the card `status`.

## Find the base branch

`<base>` is the base branch from the profile `Gate` section, with one exception. A card whose `card_get` `parent` is not null can belong to an epic branch, when the profile has an `Epics` section. Fill the epic branch pattern of that section with `parent.number`, and run this:

```bash
git ls-remote --exit-code origin refs/heads/<epic branch>
```

Exit 0 means the epic branch exists, so `<base>` is the epic branch. Any other exit means the epic started before its branch existed, so `<base>` stays the profile base branch. A profile with no `Epics` section, and a card with no parent, keep the profile base branch.

## Check the worker folder

The worker folder is the folder the worker starts in. The bridge configuration makes it and removes it, and a stage never does. A worker with no `before` command starts in the project directory. `<base>` comes from "Find the base branch".

When the profile `Environment` section names a folder check, run it first. When the check fails, stop with `STAGE RESULT: blocked: no worker folder`. Then run this in the worker folder:

```bash
git branch --show-current
```

1. When it prints nothing, HEAD is detached. Run `git fetch origin <base>`, and run it once more after 2 seconds when it fails. When it fails again, stop with `STAGE RESULT: blocked: cannot fetch <base>`. Then run `git switch -c <card branch> origin/<base>`. The bridge can detach a new folder on another branch than `<base>`, so the new branch starts from `origin/<base>`. When the switch moves HEAD, refresh the environment as "Refresh the environment" says. `<short-slug>` is two to four lowercase words from the card title, joined with hyphens.
2. When it prints `<base>`, run the same `git switch -c` command.
3. When it prints a branch that starts `<prefix>`, keep that branch.
4. When it prints any other branch, stop with `STAGE RESULT: blocked: worker folder on branch <branch>`.

## Reruns

1. A linked document tagged `build-notes` whose `references` hold the tech design id is the build notes. Reuse it, and create no second one.
2. An open pull request on a branch that starts `<prefix>` belongs to this card. Never cut a new branch for it. `<base>` is the base branch of that pull request.

   Run `git branch --show-current`. When HEAD is detached, or on another `<prefix>` branch and `git status --porcelain` prints nothing, run `git fetch origin <head>`. Run it once more after 2 seconds when it fails. When it fails again, stop with `STAGE RESULT: blocked: cannot fetch <head>`. Then run `git switch <head>`. When no local branch has that name, run `git switch --track -c <head> origin/<head>` instead. When the current branch then differs from the head branch, stop with `STAGE RESULT: blocked: worker folder is not on the PR branch`.
3. Sync that branch as "Sync with the pull request branch" says. Then resume at the gate.
4. Before you create a pull request, list the open pull requests for the branch with the forge adapter. Link one it lists, and create none.

## Refresh the environment

A stage refreshes the environment after a branch switch or a merge brings commits. The refresh command belongs to the work entry in the bridge rule file, never to the skill.

1. Run `loupe env refresh` in the worker folder. The bridge runs the `refresh` command of the entry of this run, in this folder.
2. Exit 0 means the command ran, or the entry names none. Go on.
3. A CLI older than the `env` command exits 1 and prints `unknown command "env"`. Treat that output as item 5.
4. Any other exit 1 means the command failed. Stop with `STAGE RESULT: blocked: environment refresh failed`, and name the tail of its output in the sentences after it.
5. Exit 2 means this process is not a worker of a running bridge. Go on, and write `Environment refresh: skipped, no bridge` in the final reply.

## Sync with the pull request branch

The implementation stage and the fix round sync the worker folder with this procedure. Run it in the worker folder, on the head branch `<head>` of the pull request. `<base>` is the base branch of that pull request.

1. Run `git fetch origin <head> <base>`. When the fetch fails, run it once more after 2 seconds. When it fails again, stop with `STAGE RESULT: blocked: local branch diverged from origin`. Then run `git merge --ff-only origin/<head>`. When the merge succeeds, go on at item 7.
2. The branches diverged. When `git status --porcelain` prints a line, stop with `STAGE RESULT: blocked: local branch diverged from origin`. An earlier run left that work half done, and a person must judge it.
3. The commits that `git rev-list HEAD ^origin/<head> ^origin/<base>` lists are local work. Treat them as your own work. They go out with your next push, unless item 5 resets them. The list leaves out the base commits that a sync brought in.
4. Test each of these commits with the script below. A commit is a sync when it passes these tests:
   - It has two parents.
   - One of them is an ancestor of `origin/<base>`.
   - Git can make the same merge again with no conflict.

   `git merge-tree` needs git 2.38 or later. A merge that holds a conflict resolution or a hand edit is not a sync, so the procedure keeps it. The script prints one `content` line for each commit that is not a sync. On a push retry, skip this item and item 5, and merge.
5. When the script prints nothing, every local commit is a sync of the base. Run `git reset --hard origin/<head>`. The gate merges the base again, and the approval of the pull request still covers the head.
6. Otherwise run `git merge origin/<head>`. The fix round resolves a conflict with the method of "Resolve a conflict with the base" in `../../loupe-stage-fix-round/references/pull-request-feedback.md`. Use `origin/<head>` in place of `origin/<base>`, start at its third command, and stop at its commit. Skip its refresh, because item 7 refreshes once.

   The implementation stage resolves only a mechanical conflict, as the gate does. When a conflict cannot be resolved, run `git merge --abort`, and stop with `STAGE RESULT: blocked: local branch diverged from origin`. This stop form replaces the stop form of that section.
7. When the branch switch before the sync or the sync itself moved HEAD, refresh the environment once, as "Refresh the environment" says.

```bash
for c in $(git rev-list HEAD ^origin/<head> ^origin/<base>); do
  set -- $(git rev-list --parents -n 1 "$c")
  if [ $# -eq 3 ] && { git merge-base --is-ancestor "$2" origin/<base> || git merge-base --is-ancestor "$3" origin/<base>; } \
    && [ "$(git merge-tree --write-tree "$2" "$3" 2>/dev/null)" = "$(git rev-parse "$c^{tree}")" ]; then :; else echo "content $c"; fi
done
```

Never rebase, and never force-push. The reset changes only the local branch, and the merge loses no commit of the pull request branch.

## Push without force

1. Push with the command of the stage. Never pass `--force`, `--force-with-lease` or `--no-verify`.
2. The forge rejects a push when the remote branch holds commits that HEAD does not. When it does, run "Sync with the pull request branch" again as a push retry. Then run the gate again, and push again.
3. Push three times at most. When the third push is rejected for that reason, stop with `STAGE RESULT: blocked: local branch diverged from origin`.
4. A push that a hook or a branch protection rejects is not a divergence. Never route around it.

## The gate

Run these in the worker folder, in order:

```bash
git fetch origin
git merge origin/<base>
```

When the fetch fails, run it once more after 2 seconds. Another git process that updates the same remote ref makes a fetch fail, and the refs are right afterwards. When it fails again, stop with `STAGE RESULT: blocked: cannot fetch <base>`, and merge nothing.

When the merge conflicts, resolve it only when the conflict is mechanical and the gate then proves the result. Otherwise run `git merge --abort`, and stop with `STAGE RESULT: blocked: merge conflict with <base> in <files>`.

When the merge brings commits, refresh the environment as "Refresh the environment" says. A fix round that resolved a conflict with the base refreshed after that resolution, so its gate merge brings no commits and needs no refresh.

Then run the commands of the profile `Gate` section in order. Run each long command as the harness adapter says. Poll it in the foreground until it exits, and never end the turn while it runs. Run the check of the profile `Changelog` section. Then run the review of the profile `Code review` section, and follow its pass rule.

## Open the pull request

Push the branch as "Push without force" says, and create the pull request with the forge adapter, against `<base>`. Follow the profile `Pull request` section for the title, the body and the ready state.

A pull request into an epic branch is not a stacked pull request. Write `Child of epic #<parent number>, merges into <base>.` in the body. The merge stage merges it into the epic branch, and the epic pull request carries the work to the profile base branch.

A branch that holds the commits of another open pull request stacks on it. The test is `git merge-base --is-ancestor origin/<parent branch> HEAD`, which exits 0, while that pull request is open. Then create the pull request with `<base>` set to the parent's head branch, never the profile base branch. Write `Stacks on #<parent>. Merge #<parent> first.` in the body.

Put the card URL in the body. The card page route is `/projects/{projectId}/board/cards/{cardId}`, so the URL is `<instance>/projects/<projectId>/board/cards/<cardId>`. Take the instance from the prompt line `Loupe instance <url>.`, and the project id from the prompt. When the prompt lacks either, write `Loupe card <number>` instead.

Then write the changelog entry that the profile `Changelog` section names, run its check, commit, and push as "Push without force" says.

## After the push

The stage ends at the push. The app reads the pull request again after each push, so no stage waits for CI. A failed check or a conflict reaches a fix round as a fix request. Green checks move the card, and an approved pull request with green checks reaches the merge stage. Report `waiting`, and name the pull request URL.

## Finish without code

A card can end its implementation with no pull request. The worker reports the finish, and a workflow rule moves the card to the terminal column. The worker never moves the card itself. Each case below has its own form:

1. Nothing to build. An answered decision of the tech design removes all the work of the card, or of the Breakdown entry of a child. An example is a note such as "no detector" on the only decision of the card.
2. Delivered without code. The card asks only for a document, such as a measurement or a report, and no code change.
3. Already delivered. The card links only reference pull requests. A reference pull request was merged or closed before its link, so the workflow does not count it as a work link. The worker proves on the base branch that the requirement of the card already holds, for example with the falsify step of the card body. A reference that names the source of a bug fails that proof, so the worker builds the fix.

Use this section only when the case is clear from the design, its answers and the card body. When some work remains, build it from step 6. When you are not sure, record the block and stop with `STAGE RESULT: blocked: unsure whether the card has code to build`.

For a card with nothing to build:

1. Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final line: `Nothing to build: <decision id> (<answer>) removed the work.`
2. Stop with `STAGE RESULT: nothing to build [reason: nothing-to-build]`, and the structured status `finished`.

For a card delivered without code:

1. Write the document as `loupe-documents` says, and link it to the card (contract rule 5).
2. Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final line: `Delivered without code: <document title>.`
3. Stop with `STAGE RESULT: delivered without code <document review url> [reason: delivered-without-code]`, and the structured status `finished`.

For a card whose work a reference pull request already delivered:

1. Prove on the base branch that the requirement holds. When the proof fails, build the card from step 6.
2. Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final line: `Nothing to build: already delivered by pr:owner/repo#<number>.`
3. Stop with `STAGE RESULT: nothing to build [reason: nothing-to-build]`, and the structured status `finished`.

All forms create no branch, no commit and no pull request. A card with a work link never takes this section, because the rules that move the card wait for a card with no work link. A reference link is not a work link.

## Record a block

Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final paragraph that starts `Blocked:`. The paragraph names the reason, the branch, and the pull request URL when one exists. Name another card as `card:<number>` and a pull request as `pr:owner/repo#<number>`. This applies to the `Blocked:` paragraph, and to the "Nothing to build" and "Delivered without code" lines.

## Post a refusal comment

A run that acts on a pull request posts one comment when it ends `STAGE RESULT: not ready <url>: <reason>` or `STAGE RESULT: blocked: <reason>`. The comment tells a person on the pull request why the work stopped.

1. Post no comment for `waiting`. Post none for a fault that an approver cannot fix on the pull request: `no worker folder` or `preview not seeded`. `loupe MCP unreachable` is not a `blocked:` form, so it posts none either. Post none for a state that clears with no person: `not behind`, a head that moved after the event, or a check that is still pending.
2. Make the reason key. Lowercase the reason, and turn each run of characters outside `a-z` and `0-9` into one hyphen. Remove a hyphen at the start or the end.
3. Read the head commit with the forge adapter. The marker line is `<!-- loupe-refusal: <head sha> <reason key> -->`.
4. List the top-level comments with the forge adapter. When a comment holds the same marker, post nothing. A new head or a new reason posts again.
5. Post the comment with the forge adapter. Start the body with the marker line. Then write the reason, and the next step from the first sentence after the result line.
6. Never edit, hide or delete a refusal comment when the block clears.
7. When the post fails, keep the same result line. Say in the sentences after it that the comment failed.
