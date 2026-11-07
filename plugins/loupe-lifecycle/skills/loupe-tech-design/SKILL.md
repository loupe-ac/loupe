---
name: tech-design
description: "Use when the owner runs an interactive tech design session for a Loupe card, or when a prompt names /loupe-lifecycle:tech-design."
---

# Interactive tech design

Run a tech design session with the owner. Write the tech design, link it to the card, and stop. The owner's approval of the design moves the card on.

This skill is not a stage skill. It loads no `stage-contract.md` and no unattended run notes, and it asks the owner questions. Run it in the main session, because AskUserQuestion does not work in a subagent. You can send a subagent to find facts (Q1 of `loupe-interviewing`).

Never move the card. The workflow asked for this session, and the owner's approval of the design makes the move. This rule overrides the move table of `loupe-board`.

`references/session-flow.md` holds the phases T0 to T6. `../loupe-stage-tech-design/references/tech-document.md` holds the content rules of the design and of its deploy notes. `../loupe-interviewing/SKILL.md` holds the question rules Q1, Q2, Q3, Q7 and Q8, the options shape, the recap loop and the stop rule. This session has no rule Q5 or Q6, because it settles the technical choices. Q2 uses the rules of "Facts and recommendations" in `tech-document.md` as its principles.

## Procedure

1. Load the `loupe-board`, `loupe-documents` and `loupe-interviewing` instructions. Run the routing check, and load the skills that the `### Tech design` heading routes. Read the design inputs they name. They are required inputs.
2. Read the `Documents` tags in `.loupe/lifecycle.md`. The writing style comes from the routed skills and the instruction files. When they name none, write plainly.
3. Read `references/session-flow.md`, `../loupe-stage-tech-design/references/tech-document.md`, `../loupe-interviewing/SKILL.md` and `../loupe-interviewing/references/delegation.md`. Follow them for the whole session.
4. Call `card_get`. Read the tags of each linked document with `document_get`, as `../loupe-stage-product-design/references/stage-contract.md` "Document tags" says.
   - With an approved tech design, stop and tell the owner.
   - An unapproved tech design is the existing document that T6 revises.
   - With no linked tech design, search `document_list` for the title `Tech design: <card title>`. Keep only a row whose title is exactly that title. Show each such row to the owner, and ask whether it belongs to this card. When the owner says yes, link it with `card_update` (the field replaces the whole set), and apply the two rules above.
5. Run T0 to open the run. Then pick the interview method as `delegation.md` says. With an installed skill, T2 and T3 do not run. Otherwise, run the phases in order.

## Where session output goes

Put each item in its section of the tech design, as "The design sections" of `tech-document.md` lists them.

- Each question and its answer go in "Decided", with the reason, the option that lost and the cost that the choice accepts.
- A decision that the owner defers becomes an open decision section with a decision fence (Q8). The section has the worked example and the "How each option works" list of `references/design-structure.md` of the `loupe-documents` skill "Decisions". A question in chat gets no example.
- A fact you checked in the code goes in the "Checked in the code" line of its section. Never state an unchecked fact (Q1).
- The whole design follows the reader rule of `references/design-structure.md` of the `loupe-documents` skill "Write for a reader who knows the product".

## T6: write and link

1. With no existing document, call `document_create` with the title `Tech design: <card title>`. Pass `draft: true` when `document_publish` is among the tools. Set `references` to the product document id, or leave it empty when the card has no product document. Use the tech design tags, or the spelling `tag_list` already has for them. Never call `document_publish` yourself. When the tool is missing, create the document without `draft`, as `loupe-documents` rule 17 says.
2. With an existing document, call `document_revise` on it. Keep each section with a standing approval unchanged, as `../loupe-stage-product-design/references/review-round.md` "An approved section wins" says.
3. When the design adds, changes or removes a deploy item, create or revise the deploy notes, as "Deploy notes" of `tech-document.md` says.
4. Call `card_get` again. Call `card_update` with the existing `documentIds` plus each new id. Send the `relatedCards` of `card_get` unchanged, plus a `blocked-by` entry for each dependency that "Record a dependency" finds.
5. Call `card_run_close` with the card and the `sessionId` of T0.
6. Give the owner the review URL, and stop. With a draft, tell the owner that an agent reviews the document first, and that **Publish** on the draft skips the review.
