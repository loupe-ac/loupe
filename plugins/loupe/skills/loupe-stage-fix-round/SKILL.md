---
name: loupe-stage-fix-round
description: "Use when a prompt names loupe-stage-fix-round, or when a card needs one round of review feedback answered in its current stage."
---

# Fix round

Answer one round of feedback on the current stage of a card.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Load the harness adapter, read the repository profile, and connect to the Loupe tools, as it says.
2. When a rule of this round sends a problem to a card, follow the contract's "Raise a card".
3. Call `card_get`, and run the contract's column check.
4. Read the four column slugs from the profile `Board` section: product design, tech design, implementation and review. With no line for one, use `product-design`, `tech-design`, `implementation` or `in-review`. Read the card `status`, and take one branch:
   - For the product design or tech design slug, read `references/design-round.md`, and take the design round for the product document or the tech design, under the stage contract rules.
   - For the implementation or review slug, read `references/code-round.md`, and take the code round, under the contract of `../loupe-stage-implementation/SKILL.md` instead.
   - For any other column, stop with `STAGE RESULT: no fix round for column <status>; expected <the four slugs>`.

Write the final reply as `../loupe-stage-product-design/references/stage-contract.md` "Final reply" says.
