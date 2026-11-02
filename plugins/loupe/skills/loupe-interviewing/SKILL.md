---
name: loupe-interviewing
description: "Use when a Loupe design session asks the owner questions: the question rules, the recap, the stop rule, and the interview method when no installed skill leads."
---

# Interviewing

A Loupe design session loads this skill. It holds the question rules, the options shape, the intake prompt, the recap loop and the stop rule. The rules apply when the Loupe method leads the interview, and also when an installed skill leads (see `references/delegation.md`).

Each rule has a stable ID, so a comment can cite it. The IDs Q4, Q5 and Q6 are not here. Q4 is retired and must not be reused. The product design session holds Q5 and Q6. Keep the other IDs as they are.

The session that loads this skill names its own design principles, its decision log and its document sections. This skill names no principle and no section.

## Question rules

1. Q1: Find facts yourself. Code, existing behaviour, docs and board history are facts. Look them up, with a subagent when that helps. Never ask the owner for a fact. Ask the owner only for decisions.
   - A subagent only reads. It asks the owner nothing, and it changes no card and no document.
   - Check each fact before you use it in a question, a recommendation or the document. Read the code to the end of the path, or run the command that shows the fact. A file or a field name that looks right is not a check.
   - Never put an unchecked fact in the premise of a question. The owner answers the premise, so a wrong premise makes a wrong decision.
   - When the owner corrects a fact after a question, ask that question again.
2. Q2: Give every question a recommendation. Each question carries a recommended answer and a one-line reason why it matters. The recommendation says what the option does, in plain words, never only its label. Say when your confidence is low. Base the recommendation on the design principles that the session names.
3. Q3: Ask one question at a time. Ask one question per turn. Use AskUserQuestion when the answer has clear options, and plain chat when the tool is missing. Never bundle two decisions in one question. State the context of the question in the question, because the owner can answer after a gap.
4. Q7: Ask for priorities as trade-off pairs. Take each pair from this feature, such as an early first release against a complete permission model. Ask one pair per question, and recommend a side (Q2). Never ask the owner to rank goals that face no trade-off.
5. Q8: Defer a decision only on the owner's word. A decision goes to "Open questions" as a decision fence only when the owner's answer defers it, such as "decide later". Never defer a question yourself.

Write each answer in the decision log of the session, with the question. Write a guess in the assumptions of the document only when no code, doc, command or board history can check it, such as how users will act. Check every other guess, and write the result as a fact.

## Intake prompt

Take the problem from the card before you ask anything. Read the card and the linked documents.

When there is no card, or the card body is empty or one line, ask for a brain dump with one open prompt. Count the lines of the card body for this rule. Then draft the problem from the reply: who feels it, and the situation that triggers the need.

Otherwise, draft who feels the problem and the situation that triggers it from the card body. Show no open prompt.

When you cannot draft one of those two parts, ask one question about that part only. Ask nothing else at intake. Keep solutions out of the problem.

## Options shape

When the solution is not obvious, show two or three solution shapes. Recommend one (Q2), with your confidence and the strongest argument against it. Name the cost of each option in proportion to its real size. Keep the shapes that lost available, and record the reason each lost.

## Recap loop

Let the owner check every decision before you write the document. Skip the recap when the session logged no decision.

1. List each decision in one line, with its number, in the order you settled it. Include every kind of pick that the session logged, such as priority picks. Mark a deferred decision "deferred".
2. Ask with AskUserQuestion, with the two options "Looks right" and "Correct something". Put the whole recap in the `preview` of each option, because the question box hides the chat text above it.
3. When AskUserQuestion is missing, post the recap as plain chat text, and end the turn. The owner's next message is the answer.
4. When the owner picks "Correct something", read the correction from the owner's next message. A correction that states the new answer updates the decision.
5. A correction that opens a new choice, or changes an answer that a later answer depends on, needs a new question. Ask it with the question rules above.
6. Then show only the changed lines in the same question form, and wait again.
7. Stop when the owner picks "Looks right", or when the reply holds no correction.

## Stop rule

Stop asking when no open decision is left. Use no fixed cap of questions. Ask about every open decision in the chat, including a low-impact one, with the question rules above. Write a decision fence in "Open questions" only for a decision that the owner defers (Q8).

Then run the recap. After the recap, the document is the full readback. Ask for no other confirmation in the terminal. A correction after that comes back as a review comment, and the fix round answers it.
