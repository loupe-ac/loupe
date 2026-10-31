---
name: loupe-stage-tech-design
description: "Use when a prompt names loupe-stage-tech-design, or when a card enters the Tech design column."
---

# Tech design stage

Write or revise the tech design of one card, link it, and stop. The requirement source is the approved product document, or the card body when the card has no product document.

Change nothing but Loupe documents and the `blocked-by` links of the card, and never move the card. `../loupe-stage-product-design/references/stage-contract.md` holds the full rules.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Follow its rules for the whole run.
2. Load the harness adapter and read the repository profile, as the contract says.
3. Connect to the Loupe tools, as the contract's first steps say.
4. Load the `loupe-board` instruction.
5. Call `card_get`, and run the contract's column check.
6. Read the tags of each linked document with `document_get`. The product document has the product design tag, or a title that starts `Product design`. The deploy notes have the tag `deploy-notes`, or a title that starts `Deploy notes`.
7. Choose the requirement source:
   - A product document with `status` `approved`: read it with `document_get`. Read its answers, as the contract "Read the answers of a design" says. Every decision cites the `R` IDs it serves.
   - A product document that is not approved: stop with `STAGE RESULT: product document not approved`.
   - No product document: the owner skipped product design. The requirement source is the card body. Say so in the first section of the design, and cite the card body where a decision would cite an `R` ID.
8. Load `loupe-documents` and `loupe-recommendations`. Load the tech design instructions and read the design inputs that the profile `Instruction files` section names. They are required inputs. Read `references/tech-document.md`. It holds the content rules that this file quotes by section name. They are "Leave the drafts", "Record a dependency", "Judge the size", "The design sections", "Deploy notes", "Code sketches", "Prototype links" and "Facts and recommendations".
9. Find the tech design among the linked documents, as the contract says. It has the tech design tags, or a title that starts `Tech design`. Skip the tech design of the parent card, as `../loupe-stage-implementation/references/breakdown.md` "Build a child" says.
10. When step 9 finds none, search `document_list` for the title `Tech design: <card title>`, as the contract says. The document to reference is the product document, when there is one.

### Recheck, when the prompt names a recheck

The workflow asks for a recheck when an approved design waited for a blocker, and the blocker is done. The design describes code that the blocker had not merged when it was written. Run this branch when the prompt says this is a recheck, and the design from step 9 or 10 has `status` `approved`. When the prompt names no recheck, skip this branch.

1. Read `card_get` `relatedCards`. A recheck needs a `blocked-by` entry whose `status` is a terminal column. Otherwise stop with `STAGE RESULT: blocked: no closed blocker to recheck against`.
2. Read the design and its answers, as the contract "Read the answers of a design" says. Do not change an answer.
3. Read the code on the base branch of the profile `Gate` section, also for an epic child whose base branch is `epic/<n>`. The epic branch can lack the code of the closed blocker until the epic sync runs. List each checked fact of the design: each class, method, field, route, file and behaviour that it names. Read each one. Pay most attention to the parts that the closed blocker builds, which the design could only assume.
4. When every fact holds, stop with `STAGE RESULT: tech design current [reason: done]`. Change no document.
5. When a fact no longer holds, revise the design with `document_revise`. Change only what the new code makes wrong, and keep each ID and each answered decision. Say in the first section what changed and why. A revision sets the design back to In review. Update the deploy notes when a deploy item changed, and record each new dependency, as Revise steps 6 and 7 say. Stop with `STAGE RESULT: tech design revised <id> [reason: done]`.
6. When a decision of the owner no longer works with the new code, do not change that decision. Add a new open decision with its own ID, then revise as in step 5.

### Revise, when step 9 or 10 finds the design

1. When its `status` is `approved` and the prompt names no recheck, stop with `STAGE RESULT: tech design already approved`.
2. When its `status` is `draft`, an earlier run left it for the agent review, or stopped before it finished. Read the code and those design inputs, and check the design against them. Revise it once when the check finds a gap. Check the linked deploy notes the same way. Leave the design and the linked deploy notes as drafts, as "Leave the drafts" says. Record each new dependency, as Revise step 7 says. Stop with `STAGE RESULT: tech design created <id>`.
3. Read the code and those design inputs.
4. Judge the size again, as "Judge the size" says. A revision never changes the ID of an entry.
5. Before the `document_revise` of the round, read alone each decision section that the round changes, as Create step 6 says. Fix each one that leans on another section. Follow `../loupe-stage-product-design/references/review-round.md`. The document is `tech design`, and the requirement source is the one step 7 chose. Run Revise steps 6 and 7 below before the round stops.
6. When the round adds, changes or removes a deploy item, update the deploy notes after the `document_revise` of the design. Do this before the replies. Revise the linked notes with `document_revise`, or create and link them as "Deploy notes" says. When the design is a draft, leave new notes as a draft, as "Leave the drafts" says. When the design is in review, publish new notes with `document_publish` before the final reply, so the owner reads them with the design. Keep the deploy line of the design true. When the Solution section is approved, keep its text. In a design that predates it, the same goes for Architecture. The linked notes are then the record.
7. When the round finds a new dependency, record it after the `document_revise` of the design, as "Record a dependency" says. Call `card_get` again, and send its `relatedCards` unchanged, plus the new entry, in one `card_update`.

### Create, when neither step finds a design

1. Read the code and those design inputs. Answer each entry that applies.
2. Write the sections that "The design sections" lists, with the deploy line that "Deploy notes" names. Judge the size, as the next section says.
3. Call `document_create` with the title `Tech design: <card title>`. Pass `draft: true` only when `document_publish` is among the tools, as `loupe-documents` rule 17 says. Set `references` to the product document id, or leave it empty when the requirement source is the card body. Use the tech design tags, or the spelling `tag_list` already has for them.
4. When the feature adds, changes or removes a deploy item, create the deploy notes, as "Deploy notes" says. Their `references` hold the new design id.
5. Link the new ids to the card in one `card_update` (contract rule 5).
6. Run your last checks. Read each decision section alone, and fix each one that leans on another section, a card or a term that the design defines elsewhere. Revise the drafts when a check finds a gap. Leave them as drafts, as "Leave the drafts" says. Stop with `STAGE RESULT: tech design created <id>`, with the id of the design.

Write the final reply, its reason code and the structured result as the contract "Final reply" section says.

