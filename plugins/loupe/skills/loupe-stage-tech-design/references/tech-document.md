# Tech document rules

The rules for the content of a tech design and its deploy notes. `../SKILL.md` (the stage) and `../../loupe-tech-design/SKILL.md` (the interactive session) both read this file. A rule that says "stage" or "run" applies to the stage worker. The session applies the same document rules, and its own steps decide when to write and to link.

## Leave the drafts

1. Never call `document_publish` for a tech design or its deploy notes. The design and its deploy notes stay drafts, whatever the workflow template. An agent reviews the design, and the app publishes both documents when the review ends, as `loupe-documents` rule 17 says.
2. When `document_publish` is not among the tools, the documents are not drafts, and the owner reads them at once. Nothing else changes.

## Record a dependency

A dependency is a part that the design needs, that is missing on the base branch, and that one open card builds.

1. Look for a dependency while you read the code. Find the card that builds the part with `card_search` when the tool exists. Otherwise page `card_list` while `hasMore` is true, and read the titles. `card_list` returns the columns with their `terminal` flag. Call `card_get` on the card, and check that its status is not a terminal column.
2. When you find one, add a `blocked-by` entry for that card to the `relatedCards` of the card you design. On the Create path, do it in the same `card_update` that links the document (contract rule 5). On the Revise path, Revise step 7 writes it. Send the `relatedCards` of `card_get` unchanged, plus the new entry.
3. A card that already carries the link needs no write.
4. Name the dependency in the Solution section, on the line `Depends on: card N (title), which builds X`, above the deploy line. With no dependency, write `Depends on: none`.
5. A revision that finds a new dependency adds the link, as Revise step 7 says. A revision that no longer needs a dependency says so in the design and leaves the link. Only a person removes a link, because the stage cannot tell a link it wrote from a link a person added.
6. An epic with the link waits in Tech design after the owner approves the design, until the blocker is done. The breakdown then cuts the epic branch from a base branch that holds the blocker's code.

## Judge the size

1. Judge whether one pull request of normal size can build the design. A worker builds a small task with clear limits better than a large one.
2. When the design needs more than one such pull request, add a Breakdown section. Each entry becomes one child card. Write it in the format of `../../loupe-stage-implementation/references/breakdown.md`.
3. Otherwise add no Breakdown section.
4. Mark an entry `Follow-up.` when it meets the test of `../../loupe-stage-implementation/references/breakdown.md` "Post-epic work", such as dropping a column that the epic deploy still reads. Write in its text that it ships after the epic. Write the marker right after the title, as `../../loupe-stage-implementation/references/breakdown.md` "The format" says. A follow-up entry names only other follow-up entries in `Blocked by`, and no child entry names a follow-up entry.
5. Never write a Breakdown section for a card that has a parent, because epics do not nest. A child with no entry line of `../../loupe-stage-implementation/references/breakdown.md` gets a design of its own, like any other card.

## The design sections

Use these `##` sections, in this order. Follow `../../loupe-documents/references/design-structure.md` for the reader rule, At a glance, the decisions and the tables. The reader rule is its section "Write for a reader who knows the product". Put each section that the profile instructions add, such as the current state or the project checks, before Decided. Leave out a section that has nothing real to say.

1. At a glance.
2. Problem. Say in plain words what goes wrong today, or what the product cannot do, and who feels it. Keep the trigger, the event that made someone look, apart from the problem. Leave the solution out.
3. Priorities. Cite the `P` entries of the product document that the design serves. With no product document, take them from the card body.
4. Solution. Describe the recommended solution in plain words, for a reader who knows the product. Then show a diagram of it, as `../../loupe-documents/references/design-structure.md` "Diagrams" says. Then write a "How it works" numbered list for the implementer. It gives each part that the change adds or changes, with its code name, and the main flow step by step. When the product document has a Design system section, name each component change from its list. A needed component change that the list lacks becomes a decision for the owner. End the section with the `Depends on:` line that "Record a dependency" names, and then the deploy line that "Deploy notes" names.
5. Strengths and weaknesses. Write two numbered lists: what the recommended solution does well, and what it costs or risks. Name each weakness in proportion. A design with no weakness has not looked.
6. Alternatives. Describe each rival whole solution that you considered and did not recommend. Give a table with the columns Option, Pros and Cons, in an options fence, with the recommended solution as the first row. Say in one sentence why each rival lost. When the owner should choose between the solutions, write the choice as an open decision instead. A choice inside the recommended solution belongs in its own decision section, not here.
7. How others do it. Give two or three libraries or systems that solve the same problem. Link each one, and give one takeaway.
8. Security. Write it when the change touches one of these: access control, data that a user can see, a secret, input from outside, or a call to an outside service. Say what an attacker or a wrong user can try, and what stops each attempt.
9. Scalability. Write it when the change adds work per request, per card or per run, a query on a table that grows, or a background job. Give the volume today, what happens at ten times that volume, and the first limit it hits. Mark each estimate "(estimated)".
10. One section for each open decision, with a stable ID such as `D1` in its heading. Each decision cites the `R` and `P` IDs it serves. Write each one for a reader who knows the product, and so that it makes sense with no other section open (`loupe-documents` rule 18). Give each one the worked example and the "How each option works" list of `../../loupe-documents/references/design-structure.md` "Decisions".
11. Decided. Write each entry in two to four sentences. Give the reason, the option that lost and why it lost, and the cost that the choice accepts. For a reversal, name the answer that lost and the argument that changed it.
12. The work order. List the steps with stable IDs. Say which open decision blocks which step. A step is unblocked once its decision has an answer, as the contract "Read the answers of a design" says. Write a Breakdown section instead when "Judge the size" asks for one.

Write a Light design when the product document has no Priorities section, or when the card body asks for a small change. Its At a glance is two sentences, and it skips Priorities and How others do it. It keeps Problem, Solution, and Strengths and weaknesses, each short. Security and Scalability follow the same rule as in a full design.

A design written before these sections keeps the sections it has, such as Architecture. A revision adds a new section only when a comment of the round asks for it.

## Deploy notes

The deploy notes tell the person who deploys what production needs for the change. They are a separate Loupe document. Write them when the feature adds, changes or removes one of these deploy items:

1. An environment variable, with its value or the source of its value.
2. A migration, with its risk: a long lock, a backfill or a step that cannot be reversed.
3. A feature flag that needs a migration or a manual switch-on.
4. A background worker, a message transport or a schedule.
5. An external origin or an external service.
6. An order that the deploy steps must keep, and a check to run after the deploy.

Write the notes as follows:

1. Read the profile `Instruction files` for the deploy items of the project. Add them to the list above. A profile that names none uses the list above alone, and never blocks the stage.
2. List each deploy item that the feature adds, changes or removes, and what the deploy must do for it.
3. Search `document_list` for the title `Deploy notes: <card title>`, as the contract "Find a linked document" says. Revise a single match with `document_revise`, and create no second document.
4. When no match exists, call `document_create` with the title `Deploy notes: <card title>`. Pass `draft: true` only when `document_publish` is among the tools. Set `references` to the tech design id. Leave the notes as a draft, as "Leave the drafts" says.
5. Use the tag `deploy-notes` only, or the spelling `tag_list` already has for it. Never add the product design tag or a tech design tag, because workflow rules match those tags and can move the card.
6. Link the notes to the card (contract rule 5). Send the `card_get` ids plus each new id. On the Create path, Create step 5 makes this link.

The tech design always carries one deploy line, as the last line of Solution. A design that predates Solution keeps it as the last line of Architecture. The line names the notes by their title, `Deploy notes: <card title>`. With no deploy item, the line is `Deploy notes: none`, with the reason.

## Code sketches

Show a short snippet where it makes a part or a decision concrete. Put the snippet in the section that it supports. The snippet can be an interface, a signature, a config block, a payload, or the columns of a migration.

1. Show the shape only. Write no method body and no test.
2. Keep the snippet near 20 lines.
3. Name the file that the snippet lands in, and mark the snippet as a sketch.

## Prototype links

A headless worker cannot read a claude.ai artifact, so link each prototype in a form that a worker reads.

1. Link a Design canvas, a Loupe document or a file of the repository.
2. When the product document links a stored artifact, link it too, by its stable library link.
3. Never make a new prototype in this stage. The stage changes only Loupe documents. Make a new prototype as a Loupe document.
4. Never link a claude.ai artifact that the library does not hold. When the design needs one, stop as the contract "Read a linked prototype" says. The owner stores it in an interactive session, as rule C8 of `../../loupe-product-design/references/claude-design.md` says.

## Facts and recommendations

1. Read the code before you state a fact about it. Run the search, and write the count it gives, never a count from memory.
2. A fact with no mark is checked. Mark each estimate, and each estimated cost, "(estimated)". Name the files behind the checked facts in one "Checked in the code" line per section. `../../loupe-documents/references/design-structure.md` "Write for a reader who knows the product" gives the format. Say what you could not verify.
3. Give each recommendation a confidence: high, moderate or low. Give the strongest argument against it. Say what the recommended option does, in plain words. Never write only its number (`loupe-documents` rule 18).
4. Check each assumption before the design builds on it. Read the code to the end of the path. A file or a field name that looks right is not a check.
5. Prove a claim that rests on the behaviour of a library, an API, a database or a forge, or on speed or volume. Read the current docs, with Context7 or a web search when the tools exist. Run a proof of concept when the docs do not settle it.
6. Run a proof of concept in a new temporary folder outside the checkout. Commit nothing, and delete the folder when you finish. This keeps rule 1 of the contract, which forbids a change to the repository.
7. Write each proof in the design as a checked fact: the command or the source, and the result.
8. Never inflate the cost of the option you reject. An overstated argument hides how close the call was.
9. Name the cost that each decision accepts, in the entry that causes it.
10. The owner sets the quality bar. Inform the decision, and do not make it.
