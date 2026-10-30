# Session flow

Each phase has a stable ID, so a comment can cite it. The workflow starts this session through the work kind `tech-design-session` when the card switch "Interactive session" is on. The owner can also start it by hand.

## Phases

1. T0: Start. Open an interactive run on the card.
   - Read your session id with a shell command: `echo "${LOUPE_SESSION_ID:-${CLAUDE_CODE_SESSION_ID:-$CODEX_THREAD_ID}}"`. It is the first set value of `$LOUPE_SESSION_ID`, `$CLAUDE_CODE_SESSION_ID` and `$CODEX_THREAD_ID`.
   - Call `card_run_open` with the card, `sessionId` set to your session id, and `name` set to `loupe:tech-design`. Send no `status`: the card stays in its column.
   - With no card, ask the owner for the card number. This skill needs a card, because the design is linked to it.
   - When the MCP has no `card_run_open`, skip it and every `card_run_close` call.
   - When you stop at any point after T0, call `card_run_close` before you stop.
2. T1: Intake. Take the requirement from the card before you ask anything.
   - Read the approved product document and its answers, as `../../loupe-stage-product-design/references/stage-contract.md` "Read the answers of a design" says. An unapproved product document stops the session: tell the owner. With no product document, the card body is the requirement source. Say so in the first section of the design.
   - Read the code and the design inputs that the profile names. Answer each entry that applies. Send a subagent to find facts when that helps.
   - Read the open `blocked-by` links of the card and the tech designs of the cards it follows.
3. T2: Options. Show two or three solution shapes, and recommend one with a confidence and the strongest argument against it (Q2). Skip this phase when the solution is obvious from the code and the requirement. The options and the reason each lost go in "Alternatives".
4. T3: Decisions. Ask one question per open decision, with the rules of `question-rules.md`. Ask about every decision in the chat, including a low-impact one. Q6 does not apply: a technical choice is what this session settles. Name the cost of each option in proportion (`tech-document.md` "Facts and recommendations"). Defer a decision only on the owner's word (Q8).
5. T4: Size. Judge the size, as "Judge the size" of `tech-document.md` says. When the design needs more than one pull request, draft the Breakdown entries and show them to the owner in one message. Ask whether the cut is right.
6. T5: Recap. Let the owner check every decision before you write the design. Skip the recap when the session settled no decision.
   - List each decision in one line, with its number, in the order you settled it: the solution shape of T2, each answer of T3, and the cut of T4. Mark a deferred decision "deferred".
   - Ask with AskUserQuestion, with the two options "Looks right" and "Correct something". Put the whole recap in the `preview` of each option, because the question box hides the chat text above it.
   - When AskUserQuestion is missing, post the recap as plain chat text, and end the turn. The owner's next message is the answer.
   - When the owner picks "Correct something", read the correction from the owner's next message. A correction that states the new answer updates the decision.
   - A correction that opens a new choice, or changes an answer that a later answer depends on, needs a new question. Ask it with the rules of `question-rules.md`.
   - Then show only the changed lines in the same question form, and wait again.
   - Stop when the owner picks "Looks right", or when the reply holds no correction.
7. T6: Write and link. Write the design and the deploy notes, link them, close the run, and stop, as `SKILL.md` says.

## When to stop asking

Stop when no open decision is left, then run T4, T5 and T6. Use no fixed cap of questions. After the recap of T5, the design is the full readback: ask for no other confirmation in the terminal. A correction after that comes back as a review comment, and `loupe-stage-fix-round` answers it.
