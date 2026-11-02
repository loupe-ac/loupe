---
name: loupe-board
description: "Use when working a Loupe project board through the loupe MCP: reading or writing a card, moving a card, changing the columns, or linking a document or a pull request to a card."
---

# Working a Loupe board

Each project in Loupe has one board, and each board has its own columns. A card carries a title, a Markdown body, a type, a status and a reporter. The status is the slug of the column the card sits in. The tools act on the project the connection is bound to. Each tool description says what its fields do. This skill holds the policy that the tools do not state.

## Read the columns before you name one

Call `board_columns` before you pass a `status` to any tool, and use the slugs it returns. Only `backlog` exists on every board.

A new board starts with `backlog`, `next`, `in-progress` and `done` (terminal). The project owner can add, rename, reorder, flag and delete those columns, so never assume them. A slug you read in an earlier session can be gone.

A column change reshapes the owner's board. A rename changes the slug, which breaks every outside reference to the old slug. Change a column only when the owner or your task asks for it. Otherwise, ask the owner when the board needs a column it does not have.

This skill names a column by its role. Match each role to a column of the board by its flags and its label:

- Backlog holds work nobody has chosen yet. It is `backlog` on every board. A child of an epic in Backlog is parked, and the workflow never starts it.
- The chosen column holds work that is picked and not started. It is `next` on a new board. The workflow moves a new child of an epic there. It starts the child from there once its epic sits in the working column.
- The working column holds work under way. It is `in-progress` on a new board.
- A terminal column holds finished work. It is `done` on a new board.

When no column fits a role, leave the card where it is and tell the owner.

## Search before you write a card

Call `card_search` with the words you would use to describe the work, before you call `card_create`. A read of the whole board answers the same question at far more cost, and it is the step a session skips.

Matching is by substring, ignoring case, so `pag` finds `page` and `paging`. Every word must match, and a number such as `12` also finds card 12.

- Quote a phrase to require it.
- Put `-` in front of a word to exclude it.
- Write `or` between two words to accept either.

## Read the board

Read the board as summaries, then call `card_get` for the one card you want. A `card_list` page with `full` is much larger, and a whole board of full cards once overran a caller's context limit.

`card_list` with `paused` reads the cards that a workflow pause holds. The `loupe-workers` skill says how to end a pause with `card_pause_release`.

The feedback items of a card are in `siteReviewComments`. The `loupe-site-review` skill carries the loop that fixes them and marks them addressed.

## Link documents, artifacts and cards

`relatedCards` replaces every link that touches the card, including links written from the other card, and nobody tells that card's writer. Read the card with `card_get` before you write, and send its whole list back with your change.

## Card types

The shipped templates declare these types:

- `feature`: a new or extended capability
- `bug`: something behaves incorrectly today, including a latent fault
- `security`: exposure, hardening, or a credential concern
- `tooling`: the development environment, the gates, the scripts, the build
- `docs`: documentation-only work
- `idea`: long-horizon thinking, with no commitment yet
- `epic`: one feature that is too large for one pull request, split into child cards

## Finish a card

There is no delete tool. You finish a card when you move it to a terminal column. That move also resolves the pending and addressed feedback of the card. A move back out leaves the feedback resolved.

## Say whether the parent design covers a child

A child of an epic whose tech design is approved needs a `childDesign` choice. Read the parent design before you choose. When you are unsure, choose `own`.

## A card that opens with `**Parked.**` is paused

The board has no parked state. A card whose body opens with `**Parked.**` waits for the owner. Leave it in the column it sits in, which is normally Backlog. Never move it to the chosen column or the working column yourself.

Only the owner parks a card. Write that line yourself only when the owner parks the work.

## Reporter says who raised the card

`reporter` records who first raised the card. Attribute a card to whoever raised it, not to whoever typed it.

- A card the owner dictated or decided is `human`, even when you write it down.
- A card you found on your own is `agent`.
- An unclear or unattributable card is also `agent`, because agents absorb the ambiguity, never the person.

## Set the human review switch

A card has a "Requires human review" switch. With it off, the pull request of the card can approve itself once the agent review passes and the checks are green. With it on, a person approves the pull request.

Pass `requiresHumanReview` to `card_create` or `card_update` to set it. Turn it on for a card that changes what a person sees and has a prototype. Leave it off for any other card.

## A card asks somebody to do something

Every card names work with an addressee. It ends when somebody does that work and moves it to a terminal column. The board is no notepad.

Before you write a card, say in one sentence what somebody must do. A card that cannot finish that sentence is an observation, and an observation asks nothing of anyone.

| You have | Where it goes |
|---|---|
| Work somebody must do later | A card |
| A lesson worth applying next time | The skill that covers that area |
| A fact about how the system behaves | `docs/`, or the skill |
| A record of what happened | The commit message or the pull request body |
| A problem in the code your branch touches | A fix in that branch |

A lesson is the common mistake, because it feels valuable and it has no owner. Write it into the skill a future session already reads. A card that holds a lesson sits in Backlog forever, because nobody can finish it.

The test still holds for a card whose body is mostly evidence, with one line at the end that asks for a write-up of the evidence. That is a card, because the write-up is the work. Put the evidence in the body, so that whoever takes it needs no other source.

## What makes a good card

- Keep one concern per card. Split the card when its body collects a second independent piece of work.
- Write a body that makes sense to a session with no context. Say what the work is, why it matters, and where the relevant code lives.
- Use absolute dates, such as 2026-09-06. Never write "yesterday" or "last week".
- Name the real thing: the class, the route, the file, or the feature. A task number, a wave label, a phase name or a spec section belongs to one session and means nothing later. A pull request number or an issue link is fine, because it resolves anywhere.
- The board is as public as the instance that holds it. Write no secrets, no customer names, and no complaints about people.

In a body, name a card as `card:<number>` and a pull request as `pr:owner/repo#<number>`. The entry line `Breakdown item B3 of card #214.` keeps its form, because the breakdown matches children on it.

## Move the card as the work moves

The card reports where the work stands. A session that reads the board mid-week sees the truth only when every step updates the card.

| Moment | Do this |
|---|---|
| You start the work | Move the card to the working column before you write any code. When the workflow template refuses the move, leave the card where it is. |
| A design document exists | Add its id to `documentIds`, before the code exists. |
| You open the pull request | Add its URL to `pullRequestUrls`. A draft already has a URL. |
| You hand the work over | Put the branch name and the remaining steps in the body. |
| You stop and leave the work | Move the card back to Backlog. Say why in the body. A child of an epic that you move back to Backlog stays parked. |
| The pull request merges | Move the card to a terminal column, unless Loupe already moved it. |

Attach a link as soon as it exists. A session that reads the card while the branch runs then finds the URL and the document.

Read the `**Parked.**` section above before you move a card out of Backlog.

`body` replaces the whole body, in the same way that `pullRequestUrls` replaces the whole set. Read the card with `card_get` first. Add your lines to the Markdown it returns, and send the whole result. A two-line handover note sent on its own erases the card.

### A move can start work

The workflow of the board checks a card after each move. When a rule of its template fires, it can move the card again, or ask a bridge for work on it. A bridge that runs that kind of work then starts a worker.

- A rule fires only when its condition turns true. A move back into the same slot does not fire a rule that already fired.
- When no bridge runs, the request waits until a bridge takes it. The card shows "No bridge is online to take this work." as Waiting.
- A card that a person made unmanaged gets no work, whatever you move.
- A managed card takes only the moves that the template lists, and it refuses any other move.

`references/workflow.md` says how the workflow starts work, and what a rename does. Before you move a card into a column only to hold it, ask the owner which slot asks for work.

## Link a pull request to a card

You make the link by hand, in three steps. Nothing automates any of them.

1. Name the branch after the card number, for example `feat/42-drag-ordering`.
2. Put the card's URL in the pull request body. A card page is at `<instance>/projects/<projectId>/board/cards/<cardId>`. The last segment is the UUID, never the number. `project_current` returns the project id.
3. Call `card_update` as soon as you open the pull request, draft included, with its URL in `pullRequestUrls`.

`pullRequestUrls` replaces the whole set. Read the card with `card_get` first, and send the URLs it already holds with the new one.

Step 3 is a convention, and a session sometimes forgets a convention. With a branch named after the card and a card URL in the pull request body, a person can recover the link by hand.

## Who moves a card after a merge

For a repository connected through the GitHub App, with the board automation on, Loupe moves the card itself.

- Green checks move a card from the working column to the review column that the workflow template names.
- A merge moves the card to a terminal column once each of its pull requests is merged or closed.
- When each pull request is closed and none merged, the card stays where it is.

For any other link, an agent or a person moves the card.

## Common mistakes

| Mistake | Reality |
|---|---|
| Looking for a `card_delete` tool | There is none. Move the card to a terminal column. |
| Sending only the new value in `pullRequestUrls` or `relatedCards` | The field replaces the whole set. Send the whole list from `card_get`. Omit the field to keep it. |
| Sending a short `body` to add a handover note | `body` replaces the whole body. Read the card with `card_get` first. |
| Linking a pull request only when it is ready for review | A draft has a URL. Link it when you open it. |
| Carding a lesson so it is not lost | Nobody can finish it. Write it into the skill. |
