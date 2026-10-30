# Session flow

Each level and each phase has a stable ID, so a comment can cite it. The ID P3 is retired. Do not reuse it, so the other IDs keep their meaning. P13 runs right after P7, and P12 and P11 run between P7 and P8, so their place in the list shows when they run.

## Levels

A card does not always need a full session. "Add a Claude design capability to Loupe" needs one. "Cards need tags" needs a much shorter one.

1. L1: Light. Use it for a small addition to an existing feature. Run P0, then P1, P2, P5, P6, P7, P13, P12, P11, P8, P9 and P10. The document keeps the sections that the template lists for a Light document.
2. L2: Full. Use it for a new capability. Run every phase from P0 to P13, with P13, P12 and then P11 after P7. The document uses every section of the template.
3. L3: You propose the level. After intake, recommend a level with a one-line reason, and let the owner confirm it. Do not decide from the card title alone, because a small title can hide decisions. "Cards need tags" hides these decisions, and more:
   - Are tags per project, or shared?
   - Who may create a tag?
   - What does a deleted tag do to its cards?
4. L4: You can step up. A Light session can find a decision that Light does not cover. Then tell the owner, and offer to switch to Full. Never step down without the owner's word.

A card that needs no product design does not use this skill. The owner moves it past the Product design column by hand.

## Phases

1. P0: Start. Get the card into the Product design column, with the slug from the profile, and open an interactive run on it.
   - With no card, call `card_search` first with words from the prompt, when the tool exists. Show the owner each close match. When the owner picks one, use that card, and follow the rules for a card below.
   - With no card that the owner picks, call `card_create` with `status` set to the slug and `reporter` set to `human`. Take the title and the body from the prompt. Set `type` to `feature`, or to the type the prompt names. A create writes no move event. When `card_create` refuses the slug, create the card with no `status`. Tell the owner that an approval will not move the card. Then open the run as below.
   - With a card, check its product document first, as `SKILL.md` step 5 says. When that document is approved, stop before any move.
   - Read your session id with a shell command: `echo "${LOUPE_SESSION_ID:-${CLAUDE_CODE_SESSION_ID:-$CODEX_THREAD_ID}}"`. It is the first set value of `$LOUPE_SESSION_ID`, `$CLAUDE_CODE_SESSION_ID` and `$CODEX_THREAD_ID`.
   - When the card sits in the Product design column, call `card_run_open` before P1. Send the card, `status` set to the slug, `sessionId` set to your session id, and `name` set to `loupe:product-design`. The card stays there.
   - When the MCP has no `board_columns` tool, call `card_run_open` in the same way from any column, with no check. The owner chose this, because the owner names the card by hand.
   - Otherwise, call `board_columns`. The list is in board order, and each column has a `terminal` field.
   - When the card column is terminal, or comes after the slug in the list, stop. Name the column to the owner.
   - When the card column comes before the slug, call `card_run_open` in the same way. The card moves, and this move reports your own state.
   - When the list holds no column with the slug, call `card_run_open` with no `status`. The card stays where it is. Tell the owner that an approval will not move the card, and go on with P1.
   - When `card_run_open` refuses the slug, call it again with no `status`. The card stays where it is. Tell the owner that an approval will not move the card, and go on with P1.
   - When the MCP has no `card_run_open`, call `card_update` wherever a rule above sends the slug. Where a rule sends no `status`, move nothing. Skip every `card_run_close` call.
   - When `card_update` refuses the slug, keep the card where it is. Tell the owner that an approval will not move the card, and go on with P1.
2. P1: Intake. Take the problem from the card before you ask anything.
   - Read the card, the linked documents and the code for the current behaviour. Read the unapproved existing document too, when one exists.
   - When there is no card, or the card body is empty or one line, ask for a brain dump with one open prompt. Then draft the problem from it: who feels it, and the situation that triggers the need. Count the lines of the card body for this rule.
   - Otherwise, draft who feels the problem and the situation that triggers it from the card body. Show no open prompt.
   - When you cannot draft one of those two parts, ask one question about that part only. Ask nothing else at intake.
   - Keep solutions out of the problem.
3. P2: Calibrate. Propose the session level (L3), and let the owner confirm it.
4. P4: Options. When the solution is not obvious, show two or three solution shapes, and recommend one. Skip this phase for a small card. When the options differ in what the user sees, follow `claude-design.md`. Make the offer when no visual choice came before. Otherwise, use the project or the sketches of the first answer.
   - In a Full session, first find two or three products, also when the solution is obvious. Choose products that solve the same problem. Use a subagent or a web search, when the tools exist. The findings go in "How others do it".
5. P5: Scope. Fix the first slice, which is the smallest end-to-end slice that works. Fix the no-gos, and what waits for later.
   - In a Full session, then name two or three trade-offs that this feature faces. Ask the owner to pick a side on each (Q7). The answers go in "Priorities".
6. P6: Behaviour. Settle the main journeys, the empty and error states, and who may act. Work through the coverage checklist of `question-rules.md`. Ask about docs and the landing page here when the profile is missing.
7. P7: Human review. Ask one question: "Must a person review the pull request for this card?"
   - Ask with AskUserQuestion, with the options "Yes, a person reviews it" and "No, the agent review is enough". Recommend "Yes" when the change alters what a person sees and the session made or linked a prototype. Recommend "No" otherwise. Mark the recommended option "(Recommended)".
   - Read the card with `card_get` before you ask. When `requiresHumanReview` is already true, say so in the question, and keep "Yes" as the first option.
   - Set the switch with `card_update`, passing `requiresHumanReview` and no other field. Do it when the owner answers, and never lower a switch that is on without the owner's "No".
   - A linked prototype turns the switch on by itself later. A "No" answer is the owner's word, so the card keeps it until a new artifact reaches the card.
   - The question and the answer go in "Decisions log".
8. P13: Tech design. Ask one question: "Run tech design with me?" It decides whether the tech design is an interactive session or an agent's work.
   - Ask only when the `values` of `card_get` list the key `tech-design-interactive`. The template of the card declares that key. A card whose template declares no key gets no question, because the switch would change nothing. Skip the phase, and log nothing.
   - Recommend "Yes" when the card settles an architecture, an entity model, a module boundary or a subsystem. Recommend "No" otherwise. Mark the recommended option "(Recommended)".
   - Ask with AskUserQuestion, with the options "Yes, we run tech design together" and "No, an agent writes it". When the key already holds a value, say so in the question, and keep the current value as the first option.
   - Set the key with `card_update`, passing `values` set to `{"tech-design-interactive": true}` or `false`, and no other field. Do it when the owner answers. A refused write leaves the key unchanged: tell the owner, and log the refusal.
   - The question and the answer go in "Decisions log".
9. P12: Prototype check. Compare the answers with the prototype before the recap. Skip it when the session made or linked no prototype. A prototype is a claude.ai artifact, a Design canvas, or a library artifact. An ASCII sketch (C5) is not one.
   - Read the current prototype as C9 of `claude-design.md` says. Compare only the option that the owner picked, by the label of the "Decisions log" entry.
   - Compare each entry of the "Decisions log" with it. A conflict is a field, an order, a label or a state that an answer and a shown screen contradict, or a state that an answer adds to a screen the prototype shows. A screen that the prototype never draws is not a conflict.
   - List the conflicts for yourself, in a numbered list. Ask one question per conflict, with the rules of `question-rules.md` (Q2, Q3).
   - Ask with AskUserQuestion, with the options "Keep the answer, revise the prototype", "Keep the prototype, change the answer" and "Decide later". Put the answer and the part of the prototype side by side in the `preview` of each option, because the question box hides the chat text above it. Mark the recommended option "(Recommended)".
   - "Keep the answer" revises the prototype in the source that the owner edits. Revise a canvas in the same Design artifact (C3), and read back the owner's check (C4). Revise a claude.ai artifact in the chat, and confirm the change with one AskUserQuestion. When C8 stored the prototype, push the new files as a new library version, as C8 says.
   - For a library artifact that the session linked with no canvas, read its files with `artifact_read` into a folder outside the checkout. Change the files there, and confirm the change with one AskUserQuestion. Then run `loupe artifact push <folder> --artifact <artifact id>`.
   - "Keep the prototype" changes the "Decisions log" entry. When a later answer depends on it, ask that answer again.
   - "Decide later" is the owner's word (Q8). The conflict goes in "Open questions".
   - Log each conflict and its outcome in the "Decisions log". The recap of P11 then shows them.
   - When no conflict is left, say so in one line, and go on.
10. P11: Recap. Let the owner check every decision before you write the document. Skip the recap when the session logged no decision.
   - When the questions end, list each entry of the "Decisions log" in one line, with its number, in the order you asked it. Include the Q7 priority picks and the Design canvas picks, because the log holds them too. Mark a deferred decision "deferred".
   - Ask with AskUserQuestion, with the two options "Looks right" and "Correct something". Put the whole recap in the `preview` of each option, because the question box hides the chat text above it.
   - When AskUserQuestion is missing, post the recap as plain chat text, and end the turn. The owner's next message is the answer.
   - When the owner picks "Correct something", read the correction from the owner's next message. A correction that states the new answer updates the log entry.
   - A correction that opens a new choice, or changes an answer that a later answer depends on, needs a new question. Ask it with the rules of `question-rules.md`.
   - Then show only the changed lines in the same question form, and wait again.
   - Stop when the owner picks "Looks right", or when the reply holds no correction.
11. P8: Acceptance. Draft Given/When/Then scenarios that cite `R` IDs. They go in "Scenarios". Do not ask the owner to confirm them in the terminal.
12. P9: The document is the full readback. Ask the owner nothing in the terminal after P11. The owner reviews the `R` entries and the scenarios in the review UI of the document. A correction comes back as a review comment, and `loupe-stage-fix-round` answers it.
13. P10: Write and link. Write the product document, link it to the card, with the library link of each prototype that C8 of `claude-design.md` stores, close the run with `card_run_close`, and stop, as `SKILL.md` says. Never move the card, because the owner's approval moves it.

## When to stop asking

Stop when no open decision is left, then run P7 and P13 when they have not run, then P12, P11 and P8 to P10. Use no fixed cap of questions. Ask about every open decision in the chat, including a low-impact one, with the rules of `question-rules.md`. Write a decision fence in "Open questions" only for a decision that the owner defers (Q8).
