# A finding that needs the owner

Read this file when a `spec` finding of the agent review of the head has `needsPerson` true. Such a finding goes to the owner as one blocking question. Fix every other finding first.

1. Fix every finding that has no mark, and commit it. Do not push.
2. Load `loupe-documents` and `loupe-inbox`. Write a Loupe document tagged `spec-review` with `document_create`. Link it to the card with `card_update`, and send the `card_get` values plus the document. Title it with the card number and the short head commit. For each marked finding, give its title, what the design says, and what the code does. Reference the designs.
3. Call `inbox_search`. When an open item already asks the same thing, add it with `inbox_join`. Otherwise call `inbox_ask` with one blocking `question` per marked finding, with options when the choices are clear. Link the card and the document, and pass your session id and bridge id. Name another card as `card:<number>` and a pull request as `pr:owner/repo#<number>` in the body of each item, never in an option.
4. Stop with `STAGE RESULT: blocked: spec finding needs the owner`. The first sentence after it is "Answer the question in the inbox, then resume this run." End the turn, and push nothing.
5. On the resume, read the answer with `inbox_get`, and pass your session id as `readerSessionId`.
   - When the answer says to follow the design, change the code to follow it.
   - When it accepts the code, add an entry to the "Departures" section of the build notes. The entry names the requirement or decision, what the code does instead, and the owner's answer. Revise the build notes with `document_revise`. The next review then shows the departure as a `nit`.
6. Run the gate, push, and go on with the round.

A departure that the implementer records while it builds, before any review, keeps its own rule.
