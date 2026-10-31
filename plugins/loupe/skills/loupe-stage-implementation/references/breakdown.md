# The Breakdown section

A tech design splits a card that is too big for one worker into child cards. The tech design stage writes the section. The implementation stage reads it and creates the children. Both stages read this file.

## The format

1. The section is the `##` heading `Breakdown`, with a numbered list.
2. Each entry is one child card. It has a stable ID, a title, what it covers, and its blockers:

   ```markdown
   1. **B3: Add swimlanes to the board.** Covers D5 and D6. Blocked by: B1, B2.
   ```

3. The ID is `B` and a number. The text after the ID, without the final period, is the title of the child.
4. `Covers` names the design sections that the child builds. The child builds those sections and nothing else.
5. `Blocked by` names the IDs of the entries that must finish first. Write `Blocked by: none` for an entry with no blocker. A blocker can also name a decision of the design, such as `D2`. The run of the child checks that decision. The blocker is closed when the decision has an answer, as `../../loupe-stage-product-design/references/stage-contract.md` "Read the answers of a design" says. A decision blocker gets no `blocked-by` link.
6. An entry can carry the marker `Follow-up.` right after its title. It is post-deploy work that can start only after the epic ships. The breakdown creates it outside the epic, as "Run the breakdown" step 7 says:

   ```markdown
   4. **B5: Drop the stuck announcement column.** Follow-up. Covers D7. Blocked by: none.
   ```

   A follow-up entry names only other follow-up entries in `Blocked by`. A child entry never names a follow-up entry, because the epic would then wait for work that waits for the epic. "Post-epic work" holds the test for an entry that needs the marker.
7. An ID never changes across revisions. Give a new entry the next unused number. Never reuse the ID of a removed entry.
8. Keep the blockers free of loops. A child in a loop never starts.

## Post-epic work

Only the `Follow-up.` marker makes an entry a follow-up. The tech design stage, the design review and the breakdown use the test below, so they agree on which entry needs the marker.

An entry reads as post-epic work when its text says one of these:

1. It ships after the epic, or in a later release.
2. Its pull request targets the default branch, and not the epic branch.
3. It waits for the end of a release, or for the deploy of the epic.

The breakdown stops on two contradictions (step 3a of "Run the breakdown"):

1. An entry reads as post-epic work and has no `Follow-up.` marker.
2. A child entry names a follow-up entry, or an unmarked post-epic entry, in `Blocked by`.

The test is a judgement on prose. It only stops a run. It never adds a marker, and it never moves a card.

## The entry line of a child

The entry line opens the body of each child and names its entry:

```markdown
Breakdown item B3 of card #214.
```

`#214` is the number of the epic. A parked child keeps its `**Parked.**` line above this line. A follow-up opens with its own line, `Follow-up item B5 of card #214.`, and a rerun matches it to its entry the same way. The follow-up line is optional, because the `follow-up-of` link makes the card a follow-up.

A rerun matches a card to its entry by this line first, and that match is certain. A child with no entry line matches by judgement. A follow-up with no follow-up line also matches by judgement. Read the title and the body of the card against the text of the entry and the design sections it covers.

1. A match is sure when one card plainly covers one entry. A child matches only a child entry, and a linked follow-up matches only a follow-up entry.
2. A match is unsure when two cards seem to cover one entry, or one card seems to cover two entries. It is also unsure when you cannot tell.
3. A card that links a tech design other than the design of the epic is standalone. It matches no entry by judgement.
4. A card that no entry covers stays as it is.
5. A matched card keeps its title. The breakdown adds only the entry line or the follow-up line, the entry and the link to the design.

## Run the breakdown

The breakdown changes the board, and the remote branches when the profile has an `Epics` section. It writes no code and no file. `<design>` is the approved tech design, and `<default>`, `<implementation>`, `<review>` and `<terminal>` are the slugs of the profile `Board` section.

1. Read every page of `card_list` with `parentCardId` set to the card id and `full` set, so each row carries its body and its `pullRequests`. Match each entry of the Breakdown section to a child, as "The entry line of a child" says. Match a follow-up entry through the `relatedCards` of the epic (`card_get`), where a follow-up reads `has-follow-up`. Read each such card with `card_get`, and match it by its follow-up line, or by judgement when it has none. A follow-up has no parent, so `card_list` never returns it.
2. When a match is unsure, load the `loupe-inbox` instruction. Read the answers of your earlier asks with `inbox_list` and `readerSessionId`, as `loupe-inbox` says. A card that the owner picks matches the entry. An entry for which the owner picks no card gets a new card. An item that closed with no clear answer stays unsure.
3. When a match is still unsure after step 2, make one `inbox_ask` with one item for each unsure entry. Each item names the entry and its candidate cards, by number and title. Pass your session id and the bridge id of the worker. Then stop with `STAGE RESULT: blocked: breakdown match needs the owner`. The first sentence after it is "Answer the inbox ask." Record no block on the card, because the ask is the record.

   A resumed run starts again at step 1. The blocked run settles its work request. So an epic whose children are all finished can move to `<review>` or `<terminal>` while the ask is open. The column check of `SKILL.md` step 3 accepts those two moves for a resumed run, and stops on any other column. A child that the resumed run creates brings the epic back to `<implementation>`. Steps 1 to 3 change no card and no branch, so the breakdown waits until every unsure match has an answer.
3a. Check every entry of the Breakdown section against "Post-epic work", also an entry that already has a card. When an entry contradicts the epic, stop before any write with `STAGE RESULT: blocked: breakdown entries contradict the epic [reason: needs-person]`. The first sentence after it is "Mark each listed post-epic entry `Follow-up.`, or rewrite its text, in the tech design, approve the new version, then resume this run." Then write one line per finding. Record the block on the card ("Record a block" in `commands.md`), because no inbox ask holds the record. The structured result suggests `resume-run`.

   ```text
   B7: post-epic, no marker ("Ships after the epic")
   B3: child waits for B7
   ```

4. Note the type of the card. When it is not `epic`, set the type `epic` with `card_update`. `card_create` refuses a parent that is not an epic, so this step comes before step 7.
5. Read each matched child and each matched follow-up with `card_get` just before the write. With one `card_update`, add `<design>` to its `documentIds`, and send back every id it already carries. A card that links `<design>` already needs no change there. A follow-up needs that link, or its own run stops for a missing design ("Build a follow-up" step 4).

   Add the entry line and the whole entry to the top of the body of each matched child that has no entry line. Add the follow-up line and the whole entry the same way to each matched follow-up that has no follow-up line. Send the rest of the body unchanged. When the body opens with `**Parked.**`, keep that line first and put the entry line after it.
6. When the profile has an `Epics` section, create the epic branch before you create a child. Fill the epic branch pattern of that section with the number of the card. `<base>` is the base branch of the profile `Gate` section. Run this in the worker folder:

   ```bash
   git ls-remote --exit-code origin refs/heads/<epic branch>
   ```

   Exit 0 means the branch exists, so push nothing. When the branch does not exist and no child of the epic has an entry in `pullRequests`, push it:

   ```bash
   git fetch origin <base>
   git push origin origin/<base>:refs/heads/<epic branch>
   ```

   When the push fails, stop with `STAGE RESULT: blocked: epic branch push refused: <message>`. An epic with no epic branch and a child that links a pull request started on the old flow. Push nothing for it, so its children keep the profile base branch.
7. For each entry with no card, call `card_create` with no `status`, so the card lands in `<default>`. Send the title of the entry, `<design>` in `documentIds`, and the type that step 4 noted, or `feature` when that type was `epic`. The other arguments depend on the kind of entry:
   - A child entry sends the entry line and the whole entry as the body, and the card id as `parentCardId`. The workflow moves the child to Next.
   - A follow-up entry sends the follow-up line and the whole entry as the body, and no `parentCardId`. Name another card as `card:<number>` and a pull request as `pr:owner/repo#<number>`. This applies in any card body, and in the context and body of an `inbox_ask` item of this section. The entry lines keep their form. Its `relatedCards` hold one entry, the epic with the kind `follow-up-of`. The workflow moves it to Next when the epic reaches a terminal column. A follow-up is no child, so the epic never waits for it, and the count of the result line leaves it out.
8. Set the blockers in a second pass, because an entry can name a card that step 7 creates later. Do this for each child and each follow-up whose entry names blockers. Read the card with `card_get` just before the write. Send its `relatedCards` back, plus a `blocked-by` entry for each entry blocker that it does not already carry. Skip a decision blocker. A follow-up gets `blocked-by` entries for follow-up entries only, and it keeps its `follow-up-of` link. `relatedCards` replaces every link of the card, so never send the new entries alone.

The breakdown moves no child. The workflow of the board starts a child that links an approved tech design and has no open blocker, once the epic sits in Implementation. The result line is `STAGE RESULT: breakdown <n> children`. `<n>` is the number of children the epic has after step 7.

After the result line, list each match on its own short line: the entry ID, the child number, and the reason. The reason is `entry line`, `judgement` or `owner`. Then list each child that step 7 created. The bridge keeps only the first 4 KB of the reply, so write nothing else on a line:

```text
B1: #231 (entry line)
B2: #232 (judgement)
B3: #240 (owner)
Created: B4 #245, B5 #246
Follow-up: B6 #247
```

A design with no Breakdown section gives the epic no entries. The run then creates nothing and links nothing.

## Build a child

A Breakdown child has the entry line in its body. A standalone child has none. An example is a card that a person moved under an epic by hand. Another is a card that the owner answered with "Link the tech design of the epic". The epic still groups it on the board. To find the design of the epic, call `card_get` on `parent.cardId` and read its linked tech design.

1. A Breakdown child uses the tech design of its epic. The breakdown links that design to the child, whether it created the child or matched it. A Breakdown child skips product design and tech design.
2. The entry line of the body names the entry. Find the entry with that ID in the Breakdown section of the design.
3. When the design has no entry with that ID, stop with `STAGE RESULT: blocked: no breakdown item`.
4. The build notes, the code and the pull request cover only the design sections that the entry covers. Another child builds the rest.
5. A standalone child that links a tech design of its own builds that design, as any other card. It never builds the design of its epic.
6. A standalone child that links only the approved tech design of its epic is an epic-design child. The card body is the requirement. The design of the epic is the frame: the build notes follows its architecture and its decided items, and references it. The build notes covers only the work that the card body describes. Another child builds the rest.
7. When the card body asks for something that the design of the epic contradicts, stop with `STAGE RESULT: blocked: card body conflicts with the epic design`. Name the conflict in the sentences after it.
8. A standalone child that links no approved tech design stops with `STAGE RESULT: blocked: needs its own tech design: move the card to Tech design`.
9. Every child that builds UI reads the prototype of its epic: a Breakdown child, a standalone child with its own design, and an epic-design child. It calls `card_get` on `parent.cardId`, and reads the `artifacts` of that card next to its own, as `SKILL.md` step 9 says. The breakdown copies no artifact link onto a child.
10. A Breakdown child and an epic-design child read the pending notes of the parent card with `card_get` on `parent.cardId`. They mark only the notes that their own entry or card body builds, and only after the rendered check of `SKILL.md` step 13a. Another child marks the notes of its own entry.

## Build a follow-up

A card is a follow-up when it has no parent and its `relatedCards` hold a `follow-up-of` link. The follow-up line in its body is optional, and detection reads nothing else. A follow-up never runs a breakdown. It runs after the cards it follows are done, from Next. The source card is the card whose approved tech design the follow-up builds from. Steps 9 and 11 of `SKILL.md` read the artifacts and the deploy notes of the source card.

1. When the card links an approved tech design with no Breakdown section, it builds that design as an ordinary card does. The card itself is then the source card. It uses its own artifacts and its own deploy notes, never those of a followed card.
2. Otherwise the candidates are the `Follow-up.` entries of each approved tech design that the card links. The `Follow-up.` entries of each approved tech design that a followed card links are candidates too. Read each followed card with `card_get`, and each design with `document_get`. The source card is the card whose design holds the matched entry. It is usually an epic, and it need not be one.
3. With no candidate, or an answer of "None of these", stop with `STAGE RESULT: blocked: needs its own tech design: move the card to Tech design`.
4. A line in the body that names a candidate entry, such as `Follow-up item B7 of card #994.` or `Breakdown item B7 of card #994`, is a sure match. With no such line, read the title and the body against each candidate entry, with the rules of "The entry line of a child". Every sure match adds the design that holds the entry to `documentIds`, when the card does not link it. A sure match with no such line also writes the follow-up line at the top of the body. Read the card with `card_get` just before the write, and send back every id it carries. A card that names the entry by line and links that design gets no write.
5. When the match is unsure, load the `loupe-inbox` instruction. Read the answers of your earlier asks with `inbox_list` and `readerSessionId`, as step 2 of "Run the breakdown" says. An answer that names an entry makes a sure match. With no answer yet, make one `inbox_ask` that names each candidate entry by ID and title, plus a "None of these" option. Pass your session id and the bridge id of the worker. Stop with `STAGE RESULT: blocked: follow-up match needs the owner [reason: needs-person]`. The first sentence after it is "Answer the inbox ask." Record no block on the card, because the ask is the record.
6. The build notes, the code and the pull request cover only the design sections that the entry covers. Another card builds the rest.
7. The base branch is the profile base branch. A follow-up never takes an epic branch.
8. Check the entry against the code that shipped. When the shipped code makes the entry wrong, stop with `STAGE RESULT: blocked: follow-up entry conflicts with the shipped code`, and name the conflict.
