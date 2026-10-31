---
name: product-design
description: "Use when the owner runs an interactive product design session for a Loupe card or a one-line idea, or when a prompt names /loupe:product-design."
---

# Interactive product design

Run a product design session with the owner. Write the product document, link it to the card, and stop. The owner's approval of the document moves the card to Tech design.

This skill is not a stage skill. It loads no `stage-contract.md` and no harness adapter, and it asks the owner questions. Run it in the main session, because AskUserQuestion does not work in a subagent. You can send a subagent to find facts (Q1).

Move the card only in P0. Never move it when you stop, and never move it after P0. This rule overrides the move table of `loupe-board`.

P0 opens an interactive run on the card with `card_run_open`. Close the run with `card_run_close` in P10. When you stop at any other point after P0, call `card_run_close` before you stop.

`references/session-flow.md` holds the levels L1 to L4 and the phases P0 to P12. `references/question-rules.md` holds the question rules Q1 to Q8 and the coverage checklist of P6. `references/claude-design.md` holds the Design canvas rules C1 to C9. `../loupe-stage-product-design/references/design-principles.md` holds the design principles D1 to D3.

## Procedure

1. Load the `loupe-board`, `loupe-documents` and `loupe-recommendations` instructions.
2. Read the `Instruction files` section of `.loupe/lifecycle.md` for the writing style, the docs and landing page checks, and the design system entry. C7 uses the Design System artifact that it names. When the file is missing, write plainly, and ask the owner about docs in P6.
3. Find the Product design slug in the `Board` section of that file. A line names the column that holds a card in product design. With no such line, use `product-design`.
4. Read `references/session-flow.md`, `references/question-rules.md`, `references/claude-design.md` and `../loupe-stage-product-design/references/design-principles.md`. Follow them for the whole session.
5. With a card, call `card_get`. Read the tags of each linked document with `document_get`, before any move. The product document has the product design tag, or a title that starts `Product design`. Read the tag as `../loupe-stage-product-design/references/stage-contract.md` "Document tags" says.
   - With an approved product document, stop and tell the owner.
   - An unapproved product document is the existing document that P1 reads.
   - With no linked product document, search `document_list` for the title `Product design: <card title>`. Keep only a row whose title is exactly that title. Show each such row to the owner, and ask whether it belongs to this card.
     - When the owner says yes, link the row to the card with `card_update`, and send the existing `documentIds` plus its id. Then apply the two rules above. An approved row does not move the card, so tell the owner to move it by hand.
     - When the owner says no for every row, go on with no existing document.
6. Run P0 to get the card into the Product design column and open the run, as `session-flow.md` says.
7. Run the phases of the level that P2 sets. Ask each question as `question-rules.md` says. Use AskUserQuestion when the answer has clear options, and plain chat when the tool is missing.
8. Read `../loupe-stage-product-design/references/product-document.md` before P10. It is the template, and it lists the sections a Light document keeps.
9. Run P13 when it has not run, then P12, P11, then P8 to P10, when the questions end. Ask for no confirmation after P11, because the document is the full readback (P9).

## Where session output goes

Put each item in its section of the product document.

- A technical choice that Q6 parks goes in "For tech design".
- A guess that no code, doc, command or board history can check goes in "Assumptions" (A4). Check every other guess first, as Q1 of `question-rules.md` says.
- Each question and its answer go in "Decisions log" (A5).
- Each conflict of P12 and its outcome go in "Decisions log".
- A decision that the owner defers goes in "Open questions" (Q8).
- A deferred decision in "Open questions" gets the worked example of `../loupe-documents/references/design-structure.md` "Decisions", and no "How each option works" list. A question in chat gets no example.
- The whole product document follows the reader rule of `../loupe-documents/references/design-structure.md` "Write for a reader who knows the product".
- The answer of the P7 human review question goes in "Decisions log". The session sets the switch with `card_update`.
- The answer of the P13 tech design question goes in "Decisions log". The session sets the key with `card_update` `values`.
- The P8 scenarios go in "Scenarios".
- The Design canvas link and the label of each pick go in "Decisions log", and the `R` entry they shaped cites both. When a Design canvas session ran, the product document also links the recap of the picks (C6).

## P10: write and link

1. When a Design canvas session ran, add the recap of the picks to its canvas (C6).
1a. When the session pushed a canvas to the artifact library, wait for the fidelity report of C8. Show it to the owner in one message. On `pass`, link the final version as the validated prototype. On `fail` or `skipped`, follow C8. The owner fixes and checks again, or accepts. Write the `Fidelity check` line of C8 in the document for an accepted version.
2. With no existing document, call `document_create` with the title `Product design: <card title>`. Pass `draft: true` when `document_publish` is among the tools. The draft stays out of the owner's inbox while an agent reviews it, and the app publishes it when the review ends. Never call `document_publish` yourself. When `document_publish` is not among the tools, create the document without `draft`, as `loupe-documents` rule 17 says.
3. Use the product design tag, or the spelling `tag_list` already has for it. Without it, an approval moves nothing and shows no error.
4. With an existing document, call `document_revise` on it instead. When the document lacks the product design tag, or has one of the tags `design`, `product` and the tech design tags, call `document_set_tags`. Send its current tags without those, plus the product design tag. A document with both the product design tag and a tech design tag moves no card on approval.
   - Keep each section with a standing approval unchanged, as `../loupe-stage-product-design/references/review-round.md` "An approved section wins" says. When the document has open review comments, answer them as "Answer every open comment" in that file says.
5. Call `card_get` again. When the card does not link the document yet, call `card_update` with the existing `documentIds` plus the new id. The field replaces the whole set.
6. Call `card_run_close` with the card and the `sessionId` of P0.
7. Never move the card after P0. Give the owner the review URL, and stop. With a draft, tell the owner that an agent reviews the document first, and that **Publish** on the draft skips the review. The owner reviews the `R` entries and the scenarios in the document once the review ends.
