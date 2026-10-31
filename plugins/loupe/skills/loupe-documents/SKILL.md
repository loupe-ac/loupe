---
name: loupe-documents
description: "Use when writing or revising a document for the Loupe app through the loupe MCP, such as a plan, spec, RFC, audit report or retrospective."
---

# Writing documents for Loupe review

Reviewers read what you send to `document_create` or `document_revise` in the Loupe review UI. They select passages there and comment on them. Format for that UI, not for a terminal or a README.

## Rules

### 1. No H1 title in the body

Loupe's own `title` field holds the title, so an `# Heading` that repeats it shows twice. Start with content, or with the first `##` section.

### 2. Prefer numbered lists over bullet lists

Reviewers cite entries in comments, such as "point 3 is wrong". Numbers permit this and bullets do not. Use bullets only where order and reference do not matter, such as a short set of interchangeable examples.

### 3. Give cross-referenced entries stable IDs

Markdown starts a new ordered list after each `##`, so list numbering restarts at every heading. Source entries numbered 1–33 show as 1–2 under the first heading, then 1–5 under the next. A "see finding 6" then points at nothing the reviewer can see. An audit once shipped with every cross-reference broken in this way.

When entries refer to one another, give each a short ID that does not depend on rendering. Use `C1`, `H4`, `M5` for findings in severity bands, or any scheme where the prefix names the section. Lead the entry with it:

```markdown
4. **H4 — There is no administrative console command.** …
```

Refer to **H4**, never to "finding 4". Keep the list numbered as well, because rule 2 still applies. The number gives the reviewer something to point at, and the ID gives the text something stable to cite. Entries that never refer to each other need no IDs.

### 4. Open each entry of a long list with a short lead sentence

The lead sentence says what the entry is about, before the detail. A reviewer who scans the list must understand each entry from that sentence alone. They must not need to read a wall of `file:line` references or jargon.

### 5. Make decision points look like decisions

When the document offers more than one way to do something, the format must show that you ask for a choice.

1. Write an explicit "**Decision needed:**" label, or an equivalent.
2. Put the alternatives in a numbered sub-list, so that a reviewer can refer to them.
3. Name your recommendation if you have one.

Never fold alternatives into a flat sentence such as "do X or do Y." The reader takes it as an instruction and misses the choice.

When the alternatives are a clean either/or, also wrap them in a decision fence (rule 12). The reviewer then clicks an option, and does not type "option 2" into a comment for you to parse.

### 6. Revise the document after you address review comments

Send a new version with `document_revise`, so that the document shows the resolved state. An outcome recorded only in an issue tracker, the chat or a commit message leaves the returning reviewer on a stale proposal.

1. Mark each affected entry with a **Decided:** line. It names what was chosen and where the work now lives.
2. Add no revision note, changelog or "updated on <date>" header. Loupe tracks versions itself and shows them in the UI.
3. Skip the revision only when the comments changed nothing in the content. Examples are pure acknowledgements, or questions answered in chat that have no effect on the document.

A revision re-anchors open comments onto the new version. A comment whose quoted text no longer appears comes back **orphaned**, so a rewrite of a passage that a comment asked about orphans that comment. That is normal, and it is no reason to avoid a revision. Keep the reviewer's quoted phrases intact where you can, and expect the orphan count in the tool's response.

### 7. Reply to comments before you revise, or read the review again after

`document_reply_to_comment` and `document_mark_comment_addressed` take the comment ids from `document_get_review`. **`document_revise` invalidates every one of those ids.** The re-anchor copies each comment onto the new version and leaves the original behind. The old ids still exist and still look pending. So the natural order of read the review, revise, then reply writes into rows that nobody reads. Both tools reject a stale id, with a `superseded` skip or an error that names both version numbers. By then, you must write the reply again.

Two orders work:

1. Reply to each comment and mark it addressed **first**, then call `document_revise` once at the end.
2. Revise first, then call `document_get_review` again and use the fresh ids.

The first order is usually better. Your replies describe changes that the reviewer is about to see, and the status of each thread is already correct when the new version arrives.

### 8. Keep a reply to two or three sentences

A reply is a turn in a conversation, not a section of the document. Say what you did or what you concluded, then stop. Use no headings, no bold lead-ins, no bullet or numbered lists and no tables. Do not restate the comment to the reviewer, and do not repeat the reasoning that produced the answer. This is a complete reply: "Renamed to `AccountStatusChecker`, because `ActiveUserChecker` collides with `countActive()`, which already means something else here."

The length of a reply also tells you where an answer belongs. An answer that needs more than a short paragraph belongs in the document, which the reviewer reads again later. A comment thread is the worst place to keep a decision. Put the answer in the next version, and let the reply point to it, as in "Reworked that section in v4." A disagreement gets more room, because the reviewer needs the reasoning to judge it. Even then, one paragraph is enough.

### 9. Say what changed in the version description

`document_revise` requires a `description`, and `document_create` takes an optional one. Loupe shows it under that version in the version list of the review UI. A reviewer who comes back to "v4" reads it to decide whether to read the document again. Name what you rewrote, added or dropped, and why, in one or two plain sentences.

This one is useful, because a reviewer can act on it:

```
Replaced the rollout section with a phased plan, and answered the two
questions on caching. Section 3 is unchanged.
```

This one is useless, because it restates what the new version already proves:

```
Updated the document. · Revision 4. · Addressed review comments.
```

Name the sections or entries you touched, so that a reviewer can go straight to them. When something they commented on did **not** change, say so and say why, because silence reads as an oversight. Do not restate the title, the date or the version number, because Loupe records all three.

On `document_create`, the description answers a different question: what this document is, and what it exists to settle. One sentence is usually enough, such as "Design spec for the live-review widget, deciding the auth model".

### 10. Rename with `document_rename`, not with a revision

To fix a typo in a title, or to make a batch of related documents consistent, call `document_rename`. It changes the title and nothing else. Unchanged markdown sent through `document_revise` makes a version whose description can only say "renamed". Every such version is one more entry that a reviewer must skip. `document_revise` takes a `title` only when the content and the title change together.

### 11. Point at the important passages with `document_highlight`, where the instance offers it

Highlighting is off by default, and an instance with it off does not advertise the tool. **If `document_highlight` is not among your tools, skip this rule.** Nothing else in it applies, and there is no fallback. Say in prose what you would have marked, or say nothing.

Where it is on, use it. On a long document, the place where the reviewer starts to read is most of what you control. Quotes match the **rendered prose**, not your Markdown source. Every call replaces the whole set, and `document_revise` drops them. Read `references/highlights.md` before you call it.

### 12. Use a decision fence to make a choice clickable

A pair of `<!-- decision: some-id -->` and `<!-- /decision -->` comments around the alternatives shows them as radio buttons. The answer comes back in `document_get_review` under `decisions`.

1. Mark every option `- [ ]` instead to let the reviewer pick several.
2. End the one option you recommend with `(recommended: high)`, `moderate` or `low` to show three stars on it.
3. **An id is permanent once published.** **A changed id silently discards the answer**, with no error and no warning, and the decision reads as unanswered again.
4. A malformed fence falls back to a plain list, also with no error.
5. Wrap each Option, Pros and Cons table in an `<!-- options -->` and `<!-- /options -->` fence, so that Loupe shows each row as a block.

Read `references/decision-fences.md` before you write a fence. The same file says how to write the options fence.

### 13. A stray HTML comment shows as a visible note

On its own line, the comment shows as a block note. Inside a paragraph, it shows as an inline note. Never wrap such a comment in an HTML element that opens its own block. Loupe then keeps the element and drops the comment, so the note disappears with no error.

### 14. Find a document with `document_list`, not with `document_get` on every row

Each row carries the description of the document's current version, so the list alone usually tells you which document you want. These arguments narrow it, and they combine:

1. `search` matches each word as a substring of the title or the current content, ignoring case. Quotes, a leading `-` and `or` work as in `card_search`. The documents with every word in the title come first, newest first in each group.
2. `status` keeps one state: `in-review`, `approved`, `changes-requested` or `draft`. An unknown value is refused.
3. `tag` keeps the documents that carry one tag, matched ignoring case. Read `tag_list` for the project's vocabulary.
4. `series` keeps the documents in one series, matched ignoring case, and orders them by their position in it. Read `series_list` for the names.

Archived documents stay out until you pass `includeArchived`. Keep paging while `hasMore` is true, with a search or without one.

### 15. Follow `references/design-structure.md` for a product design or a tech design

The design opens with "At a glance", and each open decision gets a pros and cons table. Write the design for a reader who knows the product and not the code. Keep code names in the parts that this reader can skip, as its section "Write for a reader who knows the product" says. Read that file before you write a design.

### 16. Give the meaning of each outside ID

An outside ID is a short ID that the document does not define and does not take from a referenced document. Examples are a rule ID of a skill, a decision record number, or an ID of a document that is not in `references`. The reviewer reads the document without the source open, so a bare outside ID tells them nothing.

1. Put the meaning in a few words at the first mention of the ID in each section, such as "C2 (offer a design session only on a visual option set, from `claude-design.md`)".
2. Name the source file or document once in each section, at that first mention.
3. Put a colliding ID in a code span, with its meaning in prose: `` `P4` (the Options phase of the session, from `session-flow.md`) ``. An outside ID collides when it shares its letter prefix with IDs that the document or a referenced document defines. The review page gives a bare mention of a defined ID its tooltip. A bare outside `P4` therefore shows the tooltip of priority P4 when the document defines P4. The prefix test also protects an ID that a later revision defines. The page skips a code span.
4. The IDs of a referenced document need no meaning. The review page shows their tooltip when `references` holds that document and its list entries lead with a bold ID, such as `**R3: Title.**`. Keep the reference, and keep the bold lead in the source document.

### 17. Create a document as a draft when you can still change it before your run ends

A new document goes to the reviewer's inbox at once. A revision in the same run then changes the version that the reviewer reads. A draft stays out of review and out of the inbox, and a `document_revise` keeps it a draft.

1. Pass `draft: true` to `document_create`.
2. Revise the draft as often as you need.
3. Call `document_publish` for each draft of the run, as your last document step. The document then enters review on its final version.
4. When `document_publish` is not among your tools, the instance does not have drafts yet, and `document_create` refuses the `draft` argument. Create the document without `draft`, as before.

A product design, a tech design and the deploy notes that the tech design stage creates are the exception to step 3. They stay drafts while an agent reviews them, and the app publishes them when the review ends. Create them as drafts, and never call `document_publish` for them. The same holds when you revise one in a fix round for an agent review. Deploy notes that you add beside a design already in review are published at once.

A review round on a document that is already in review revises it once, and needs no draft step. A person in the session with you can read the document at once, so an interactive session needs no draft either. The exception is a product design, which stays a draft for the agent review.

### 18. Make each decision stand on its own

The owner often answers a decision outside its document, or after a long gap. A reader who opens only the decision section must understand it and answer it.

1. Do not lean on an earlier section, another decision, a card, a pull request or a term that the document defines elsewhere. State the fact in a sentence or two. A link can stay as a source for further reading.
2. A recommendation says what the option does, in plain words. Never write only "I recommend option 2" or "the option of D1".
3. The fence question and each option line name the subject. The decisions panel of the review page shows the question with no text around it.
4. Rule 16 still covers an outside ID. This rule also covers the IDs and terms that the document defines itself, such as `D1` or `W3`.

### 19. Name a card or a pull request with a token

Write `card:<number>` for a card of this project and `pr:owner/repo#<number>` for a pull request. Loupe shows each token as a link with a title tooltip. The stored text of the document reads "card #1116", so comment anchors keep their place. Prose such as "card 1116" also links, but a token is exact. A token inside code, a link or square brackets stays as written. A document title shows a token as plain text.

## Example

This is the shape of an entry, with the lead sentence first and the detail after:

```markdown
1. **Token flush on every request.** Each widget API call writes
   `last_used_at` synchronously. `ApiTokenAuthenticator.php:45` runs
   `markUsed()` + `flush()` inside the auth path… (detail follows)
```

Do not write this. It starts in jargon, joins fragments together and explains nothing:

```markdown
1. `ApiTokenAuthenticator.php:45` — markUsed() + flush() on every Bearer
   request · hot-row contention · fix: …
```

This is the shape of a decision. The choice is visible, and a reviewer can refer to each option:

```markdown
**Decision needed** — how to stop reset-link host poisoning:

1. Drop `x-forwarded-host` from `trusted_headers` (recommended: smallest
   change, removes the attacker input).
2. Generate emailed links from a pinned `default_uri` instead of request
   context.
```

Do not write "Drop `x-forwarded-host` or generate these links from a pinned `default_uri`." The reader cannot tell that you expect a decision.

## Common mistakes

- Pasting a report written for the terminal into `document_create` unchanged. These rules exist to undo terminal habits: dense bullets, fragments separated by `·`, and H1 headers.
- Numbering only the top level. Nested lists that reviewers discuss also get numbers.
- Cross-referencing by list number across headings. The rendered numbering restarts in each section, so the reference lands on the wrong entry or on none. Use stable IDs (rule 3).
- Holding comment ids across a `document_revise` call. They do not survive it (rule 7). Read the review again, or reply before you revise.
- Paging the whole document list to find one document. Pass `search`, or a `status`, `tag` or `series` filter, and read the version description of each row (rule 14).
- Quoting your own Markdown to `document_highlight`. It matches the rendered prose, so inline markup in the quote finds nothing (rule 11).
- Answering a one-line comment with a structured essay, with bold lead-ins, sub-points and the whole argument behind the answer. Give the answer (rule 8). If it does not fit in a short paragraph, it belongs in the document.
- Writing a table and then the same content again in prose. The table replaces the text it covers (rule 15).
- Writing a decision in the terms of the code, with class names, fields, and "(checked, File.php)" on each sentence. The owner decides with no file open. Write the question and the options in product terms. A tech design puts the code in the How list (rule 15).
- Citing a rule ID of a skill, such as C2, with no meaning and no source. The reviewer does not have the skill open (rule 16).
- Writing "I recommend option 2", "under D1 option 1" or "as in card 412" in a decision. The owner answers from the decisions panel or the inbox with no other section open. Say what the option does, and state the fact inline (rule 18).
