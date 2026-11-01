---
name: loupe-lifecycle-session
description: "Use when you do work on a Loupe card yourself, in a session the owner started, on a project whose workflow template is Lifecycle, and the work ends in a pull request."
---

# Lifecycle session

Do the work of a card yourself and keep the board true while you work. The card moves to Implementation before your first edit, and no worker starts on it.

This skill is not a stage skill. A workflow request starts no part of it. It loads no stage skill and no harness adapter. It shares `.loupe/lifecycle.md` with the stages.

## When it applies

Call `workflow_get` first. The skill applies only when the answer has the template key `lifecycle`. When the tool is missing or the key differs, stop this skill. Follow `loupe-board` and the instructions of the repository instead.

## Procedure

1. Load the `loupe-board` instruction. Read the `Board` section of `.loupe/lifecycle.md` for the Implementation slug. With no such line, use `implementation`.
2. Call `card_search` for the work. Reuse a card that exists. Otherwise call `card_create` with no `status`, so the card lands in Backlog. Never create the card in Implementation, because a worker would start before you open the run.
3. Call `card_run_open` with `status` set to the Implementation slug, and the name `loupe:lifecycle-session`. Pass your session id as `loupe-board` and the tool say.
4. Read the answer.
   - An answer that starts `worker:` means a worker holds the card. Stop. Make no edit. Tell the owner that they can wait for the worker, or stop it with `worker_run_stop`.
   - An answer that says the card is managed means the template refuses the move. Call `card_run_open` again with no `status`. The card stays where it is. A card with a parent and an epic always take this path.
   - Any other error: stop and tell the owner.
5. Do the work. Follow the instructions of the repository.
6. Open the draft pull request. Put the card URL in its body. Call `card_update` with the pull request URL in `pullRequestUrls`, and pass no `status`. Read the card with `card_get` first, and send back the URLs it holds.
7. Call `card_run_close`.

## Rules

1. Never move the card after step 3. The workflow moves it to review and to done.
2. When you stop before step 6, call `card_run_close`. The card goes back to Backlog. A later pull request link brings it back to Implementation.
3. Do not create a second card for the same work. A session that restarts reuses its card and calls `card_run_open` again.
4. A card whose body opens with `**Parked.**` stays where it is. Do not start it.
