---
name: loupe-stage-product-design
description: "Use when a prompt names loupe-stage-product-design, or when a card's product document needs one review round."
---

# Product design stage

Revise the product document of one card, and stop. Leave architecture, entity and module decisions to the tech design stage.

Change nothing but Loupe documents, and never move the card. `references/stage-contract.md` holds the full rules.

## Procedure

1. Read `references/stage-contract.md`. Follow its rules for the whole run.
2. Load the harness adapter, as the contract says.
3. Read the repository profile, as the contract says.
4. Connect to the Loupe tools, as the contract's first steps say.
5. Call `card_get`, and run the contract's column check.
6. Load `loupe-documents` and `loupe-recommendations`, then read `references/product-document.md` and `references/design-principles.md`. Apply the principles to each change you make.
7. Find the product document among the linked documents, as the contract says. It has the product design tag, or a title that starts `Product design`.
8. When step 7 finds none, search `document_list` for the title `Product design: <card title>`, as the contract says.

### Revise, when step 7 or 8 finds the document

1. When its `status` is `approved`, stop with `STAGE RESULT: product document already approved`.
2. Read code and docs only to state current behaviour.
3. Follow `references/review-round.md`. The document is `product document`, and the requirement source is the card body.

### Stop, when neither step finds a document

1. Stop with `STAGE RESULT: no product document; run /loupe:product-design`. The owner writes the document in that session.

Write the final reply, its reason code and the structured result as the contract "Final reply" section says.
