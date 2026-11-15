# A question to the owner

Read this file when a stop needs a choice that only the owner may make. The fix round reads it too. The owner answers in the inbox, in one click or one note, and does no edit.

## When a stop is a question

A stop is a question when you can name two or more valid outcomes and only the owner may pick one. A stop for the environment stays a plain block. Examples are a merge conflict, a missing folder and a failed fetch.

## Look before you ask

1. Call `inbox_list` with the `cardId` of this card, in every state. Walk each page while `hasMore` is true.
2. For a card with a `parent`, call `card_list` with `parentCardId`. Call `inbox_list` the same way for each sibling.
3. Call `inbox_search` with two or three key words. Read each candidate with `inbox_get`, and pass your session id as `readerSessionId`.
4. List each distinct decision of this stop. Drop a finding that asks the same thing as another. Then settle each decision on its own, with the next four steps. A decision with an answer never settles another decision.
5. An open item that asks the same thing as a decision: add this card to it with `inbox_join`. The decision still has no answer, so the stop below applies.
6. A resolved item of this card, or of a sibling card, that asks the same thing is the answer to that decision. Apply it. The exception is an answer that rests on a fact that differs on this card. Then the decision stays open: name that fact, and link the earlier item.
7. A declined item is an answer too. Read its note. Apply it when it is clear. The decision stays open when the note leaves the choice open or the facts differ.
8. Record each applied answer as "After the answer" says, and cite the item number.
9. A decision that step 5 joined waits for the owner in its joined item. Each other decision that no step 6 or 7 settled goes into the ask. A joined decision never removes another decision from the ask.

## Build the ask

1. Make one `inbox_ask` for the decisions that step 9 left. When none is left and no step 5 join waits, make no ask and go on with the applied answers. When none is left and a joined item waits, make no new ask and take the stop below. When a joined item waits and a decision is left, make the ask for that decision, and take the stop below. Give one item for each distinct decision. Pass your session id and bridge id.
2. Each item has `freeText` true. Put the clear choices in `options`.
3. The body of each item says what the code does now, and what the design or the card says. It gives the cost of each option for a user, in plain words. It ends with your recommendation and its confidence: high, moderate or low. The recommendation never replaces the answer. Never act on a recommendation alone.
4. Put the id of this card in `cardIds` of each item. Add each other card that the question applies to, such as a sibling that waits on the same answer. The card filter of `inbox_list` reads only this field.
5. Name a card as `card:<number>` and a pull request as `pr:owner/repo#<number>`, never by id. Link each document in `documentIds`, and write its review URL in the body: `/projects/{projectId}/documents/{documentId}/review`, with the `#heading-` anchor of the section when one applies. This covers the spec-review document, the build notes and the design section.
6. Never give the owner a step in a document, the build notes or a pull request body. The worker writes them.

## The stop

1. Stop with the `blocked:` form of the step that called this file. Record no block on the card, because the ask is the record.
2. When the card has a pull request, post the refusal comment on it, per "Post a refusal comment" in `commands.md`. A stop before the pull request posts none.
3. The first sentence after the result line is "Answer the question in the inbox, then resume this run." End the turn.

## After the answer

1. Read the answer with `inbox_get` and `readerSessionId`, or the note that the resume prompt names.
2. When the build notes exist, add a "Departures" entry or a "Decisions from the answers" entry. Cite the inbox item number. When a pull request exists, add one line to its body.
3. A stop before the build notes writes no new record. The resolved item, linked in `cardIds`, is the record. The step that writes the build notes later copies the answer into "Decisions from the answers", with the item number.
4. The breakdown applies a match answer as a board change. A "nothing to build" answer adds the card body line of "Finish without code" in `commands.md`.
5. Record an answer that was applied from an earlier item the same way.
