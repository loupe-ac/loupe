# Code round

The prompt line `Pull request <url> needs a fix: <reason>.` names what started the round. The reason is `checks-failed`, `conflict`, `changes-requested` or `agent-review`. A run by hand can have no reason. Whatever the reason, fix everything that is open:

- a conflict with the base
- each failing check
- each open feedback item
- each pending live-review note

The round ends at the push. It never waits for CI, because the app reads the new head and sends the next fix request or the merge decision.

1. Read `pull-request-feedback.md`, then read each linked pull request with the forge adapter. When none is open, stop with `STAGE RESULT: no open pull request`.
2. Read the mergeability, then the checks and the open feedback items, per the reference. Read the checks once, and never wait for a pending one. When the mergeability reads `conflicting`, or the reason is `conflict`, read `conflict.md` now. Step 8 follows it.
3. When `siteReviewComments` of `card_get` holds a note whose `status` is `pending`, read `live-review-notes.md`, and follow it for the notes and the review items that copy them. Then read the agent review of the head, per "Agent review findings" in the reference, whatever the reason of the request. A CI fix and a review fix share one live request. So a round that started for one reason must fix the other too.
4. Stop with `STAGE RESULT: nothing to fix` when all of these hold: the pull request is mergeable, no check fails, no item is open, no note is pending, and the agent review of the head holds no finding to fix. An epic pull request takes the round as any other pull request, and the round pushes to the epic branch.
5. Read `../../loupe-stage-implementation/references/commands.md`. Load the profile `Instruction files` and `loupe-recommendations`.
6. Check the worker folder, and sync it with the pull request branch, per the reference. Keep every existing commit, except a sync of the base that the sync procedure resets.
7. When the branch differs from the pull request branch, stop with `STAGE RESULT: blocked: worker folder is not on the PR branch`.
8. When the pull request conflicts, resolve it first, as `conflict.md` says.
9. Write down the head commit as the start of the fix, with `git rev-parse HEAD`. A merge of the base in step 8 then stays out of the self-review.

   Fix every open item, every pending note, every failing check and every finding of the agent review of the head. Read the log of each failed check, and fix the cause. Fix a gap that the conflict resolution shows in this round too, never in a card (implementation contract rule 12). Follow the implementation skill for sub-agents, the gate and the code review. In this round, `<base>` is the base branch of the pull request, such as the epic branch of an epic child. Before the gate, run implementation step 11 on the diff of the pull request, so the deploy notes stay true.

   Test "When the check applies" of `../../loupe-stage-implementation/references/visual-check.md` as implementation step 10a does. When it skips the check, write the `Visual check: skipped` line in the build notes and the pull request body, unless they hold it. Run the visual check after the fixes and before the self-review only when it applies and this round changed a path that the profile `Visual check` section names. Update the "Visual check" list of the build notes with `document_revise`.

   Test "When the check applies" of `../../loupe-stage-implementation/references/preview.md` as implementation step 12a does. Run that file after the gate and before the push when the check applies and one of these holds: the agent review of the head holds a `Preview:` finding, or this round changed a path that the profile `Preview` section names. Replace the Preview section of the body through its body file. A link that fails ends the round with `STAGE RESULT: blocked: preview not seeded`.
   1. Fix the rule, not the instance. For each finding, write down the rule that the code breaks. Take it from the `Rule:` line of the body, or work it out when the finding has none. Search `git diff $(git merge-base origin/<base> HEAD)` for every other place where the rule applies, and fix each one. That diff holds the committed and the uncommitted changes of the branch. Also read each new file that `git status --porcelain` lists as untracked. Treat a failing check the same way. Find the cause, then every place with that cause.
   2. Add a test that checks the rule, so it also catches the next instance. When the rule has no test, such as a convention or a documentation rule, say why in the commit message.
   3. A place outside the diff is not part of this round. It follows implementation contract rule 12.
   4. Review your own fix before the gate. Read `../../loupe-stage-review/references/checklist.md`, and apply it to every change of this round, committed or not. That is `git diff <start of the fix>`, plus each untracked file that `git status --porcelain` lists. Run the pass after the last fix and before the gate, so it never sees an empty or partial diff. Look hardest at new locking, a new branch of logic and a new state. Fix what the pass finds. Run it once, and run no second pass.
   5. End the message of each commit of this round with the trailer line `Loupe-Fix-Round: <reason>`. A merge commit of the base takes no trailer. `<reason>` is the reason of the request, or `manual` when it has none. Put it in the last paragraph, next to the other trailers such as `Co-Authored-By`, with no blank line between them. The next reviewer reads it to find the fix commits.
10. Push as "Push without force" in `../../loupe-stage-implementation/references/commands.md` says.
11. Post a marker reply for each handled item, per the reference. A conflict has no item, so it gets no reply. An agent review finding has no thread, so it gets no reply either.
12. Mark each fixed note addressed, as `live-review-notes.md` says.
13. Stop with `STAGE RESULT: waiting <pr url>`. Never move the card.
14. When a step cannot go on, record the block as "Record a block" in `../../loupe-stage-implementation/references/commands.md` says. Post the refusal comment on the pull request, per "Post a refusal comment" in the same file. Stop with `STAGE RESULT: blocked: <reason>`.
