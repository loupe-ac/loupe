# Decision fences

Rule 12 of `../SKILL.md`. Read this before you write a fence.

A decision fence turns a choice into something the reviewer clicks. Wrap the alternatives in a pair of HTML comments that carry an identifier. Loupe shows them as a group of radio buttons, and the answer comes back in `document_get_review` under `decisions`:

```markdown
<!-- decision: reset-link-host -->

Which host should an emailed reset link be built from?

1. Drop `x-forwarded-host` from `trusted_headers`
2. Generate emailed links from a pinned `default_uri`

<!-- /decision -->
```

A single paragraph before the options becomes the question of the card. It is the only prose that the fence accepts. Write it as one short question that stands on its own without the paragraph above the fence. A reviewer reads that line to know what you ask. The question is optional. A fence of only options still converts, and the card shows its id as the title. Two paragraphs are one too many. The block then keeps all of its prose, falls back to the plain list it already was, and makes no controls.

Each option line says what the option does, in plain words. Never write only a label such as "option B" or "the approach of D1". The decisions panel shows the question with no text around it (rule 18 of `../SKILL.md`).

Keep the "**Decision needed**" label and your reasoning above the fence, where rule 5 puts them. The fence holds only the question and the options.

## Mark the recommended option

Mark the option you recommend at the end of its line. Write `(recommended: high)`, `(recommended: moderate)` or `(recommended: low)` after the option text. Loupe removes the marker from the label and shows three stars next to that option: three filled for high, two for moderate, one for low. The words, such as "Recommended, moderate confidence", show on hover and on keyboard focus.

Mark one option only. A second marker cancels both, so the block shows no badge and keeps the marker text. Other Markdown renderers show the marker as ordinary text.

```markdown
1. Drop `x-forwarded-host` from `trusted_headers` (recommended: moderate)
2. Generate emailed links from a pinned `default_uri`
```

## How the reviewer answers

A click saves at once. The reviewer can also write a note, with a pick or alone. The note saves shortly after the reviewer stops typing, and comes back as `note` on the decision in `document_get_review`. A **Clear choice** button, beside the "Pick one" chip, removes the pick and keeps the note. The last write wins. A save from an older version of the document carries onto the current version by option label.

## The list of options

Numbered and bulleted lists both convert. Rule 2 applies here as everywhere, so prefer numbers. A reviewer can then still write "option 2" in a comment as well as click it.

1. Keep the entries a flat list of one-line options. A nested list is refused, and it shows as an ordinary list.
2. A second fence that reuses an id from above it is refused in the same way.
3. Every other Markdown renderer hides the comments, so a document read outside Loupe still shows the list. That is why the fence uses comments and not a visible marker.

## Show an Option, Pros and Cons table as blocks

Wrap every Option, Pros and Cons table in an options fence. Put `<!-- options -->` on its own line above the table and `<!-- /options -->` on its own line below it. Loupe shows each row as a tinted block. The first column is the name of the option, and the other columns sit side by side under their own headings. On a phone the columns stack.

```markdown
<!-- options -->

| Option | Pros | Cons |
|---|---|---|
| 1. Any project member | Matches who may create a tag | A member can remove a tag in use |
| 2. The project owner only | No surprise removals | The owner must do every clean-up |

<!-- /options -->
```

1. The fence marks every table between its two comments. A fence may hold more than one table.
2. A table needs at least two columns. A table with one column stays a plain table.
3. A fence with no table inside, an opener with no closer, and a closer with no opener all do nothing. Loupe shows the stray comment as a visible note, as it does for any comment, so you can see the mistake.
4. A table outside a fence stays a plain table. Its text is the same in both shapes, so comments on it anchor the same way.
5. Other Markdown renderers hide the two comments and show a plain table.

## Ask for one answer, or for several

A marker on the options says how many answers the block takes. Mark every option `- [ ]`, and the reviewer can tick any number of them. Mark every option `- ( )`, or mark none of them, and the block takes exactly one answer. Loupe removes the marker, so it never shows in the rendered document.

```markdown
<!-- decision: ship-with -->

Which of these ship in the first release?

- [ ] The importer
- [ ] The exporter
- [ ] The admin page

<!-- /decision -->
```

Use `- [ ]`, because it is the GFM task-list marker. The block then already reads as a set of checkboxes on GitHub and in every other renderer. Do not tick an option with `- [x]`. Loupe accepts the spelling and reports no answer for it, because only a reviewer's click is an answer.

The markers must agree. A list that mixes `- ( )` and `- [ ]`, or that marks only some of its options, is malformed. It falls back to a plain list with no error.

A multi-choice block answers in `selections`, not in `selected`. Read `type` on the decision to know which kind you asked for, because `selected` is null on every multi-choice block, answered or not.

A fence written before multi-choice shipped keeps working, and its stored answer is still reported. An unmarked list is a single-choice block, which is what those fences already are. A fence that used `- [ ]` before multi-choice shipped now asks for several answers. Change it to `- ( )` if you meant one.

## The id

An id is permanent once published. Loupe stores the answer against the id, not against the words. You can then reword a decision block in the revision that answers feedback about it. **A changed id silently discards the answer**, with no error and no warning, and the decision reads as unanswered again. Treat an id like a database column name. Rewrite the options freely, but never the id.

Ids are lowercase letters, digits and hyphens. They must **start** with a letter or digit, and 64 characters is the exact limit. An id of 65 characters is refused, and like every other malformed fence, it shows as a plain list with no error.
