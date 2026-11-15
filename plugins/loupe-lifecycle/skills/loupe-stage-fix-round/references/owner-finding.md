# A finding that needs the owner

Read this file when a `spec` finding of the agent review of the head is an owner finding. That is a finding with `needsPerson` true, or one whose body asks for an owner decision or waiver, with or without the mark. Each goes to the owner as a blocking question. Fix every other finding first.

1. Fix every finding that is not an owner finding, and commit it. Do not push.
2. Load `loupe-documents` and `loupe-inbox`. Write a Loupe document tagged `spec-review` with `document_create`. Link it to the card with `card_update`, and send the `card_get` values plus the document. Title it with the card number and the short head commit. For each owner finding, give its title, what the design says and what the code does. Reference the designs.
3. Build the ask as `../../loupe-stage-implementation/references/owner-question.md` says: look before you ask, then one item for each distinct decision. Link the spec-review document in `documentIds`.
4. Stop as "The stop" of that file says, with `STAGE RESULT: blocked: spec finding needs the owner`. Push nothing.
5. On the resume, read the answer with `inbox_get`, and pass your session id as `readerSessionId`.
   - When the answer says to follow the design, change the code to follow it.
   - When it accepts the code, add an entry to the "Departures" section of the build notes. The entry names the requirement or decision, what the code does instead, and the owner's answer, with the inbox item number. Revise the build notes with `document_revise`. The next review then names the departure in its summary and raises no finding for it.
6. Run the gate, push, and go on with the round.

A departure that the implementer records while it builds, before any review, keeps its own rule.
