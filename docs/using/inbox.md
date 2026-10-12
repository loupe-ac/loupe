---
title: "The inbox"
description: "Read and answer agent questions, review requests, and to-dos in the project inbox."
---

An agent that works for a long time produces questions and tasks that only a
person can finish. The inbox holds them. Each project has one inbox, and only
the project owner can read it or answer it.

The inbox is behind the `inbox.enabled` feature flag, and the flag ships off.
See [Turning the inbox on](#turning-the-inbox-on).

## Items and asks

An **item** is one question, review request, or to-do. Each item has a number that counts
from 1 inside the project, so you can say "item 12" to an agent.

- A **question** offers options, a written answer, or both. The agent says
  whether you can pick one option or several.
- A **review** asks you to approve a document or pull request, or request changes.
- A **to-do** asks you to do something, such as publishing release notes.
- A **waiting** item tells you that a card waits for you. Loupe opens it, not
  an agent. See [Automatic items](#automatic-items).
- A **notice** tells you about a fact of the project that needs a change.
  Loupe opens it and takes no answer. See [Notices](#notices).

An **ask** is the set of items that one agent session hands over at once. The
agent writes a short context for the ask, and the page shows that context above
the items. An item that blocks the agent carries a **Blocking** label.

## The inbox page

Open **Inbox** in the project sidebar. An amber count next to the link shows how
many items are still open. The link is absent while the flag is off.

The header keeps its View activity link visible on narrow screens, including
with enlarged text. It wraps below the title when needed.

The count changes without a reload when an agent asks, when you or an agent
close an item, and when a finished card makes an item obsolete. It goes away at
zero. This needs a Mercure hub and the `live_updates.enabled` flag. Without
them, the count is correct each time a page loads. See
[Mercure hub](../extending/mercure.md).

The page has two columns: a list of asks on the left and the selected ask on
the right. The list column opens with an **Open · N** and **Closed** switch, a
search field and a type filter.

### The address holds the view

The address of the page describes what you see, so a reload or a shared link
shows the same view. The page reads these values from the query string:

- `side` is `open` or `closed`. The address `?queue=completed` of an old link
  reads as `side=closed`.
- `type` is `question`, `review`, `todo`, `wait` or `workflow`. Without it the
  list shows every type.
- `q` is the search.
- `ask` is the id of the selected ask.
- `item` is the number of an item. The page opens the open ask that holds the
  item, else the newest closed ask that holds it, else the item on its own.
- `page` is the page of the closed asks or of the search results.

A value that does not parse falls back to its default. An `ask` of another
project selects nothing.

A click on a row, the switch, a filter, the search or the pager reloads the
list and the selected ask without reloading the page, and it adds a step to
the browser history. The back button returns to the view before. Each visit
from the sidebar starts on **Open** with every type and no search.

An old link of the form `#inbox-item-12` still works. The page turns it into
`?item=12`.

### The list

The **Open** side lists the open asks and the open items that no open ask
holds. No agent waits on such an item, but nobody has closed it yet. A to-do
from an ask that already closed is the usual case. Such an item is a row of its
own. An ask that holds an open blocking item comes first, then the others. Each
group is oldest first. The **Open** side shows every row.

The **Closed** side lists the closed asks, newest close first, ten to a page.

A row is one ask. It shows the icon of the first open item of the ask, and the
icon of the first item of a closed ask. The title of the first item follows.
A grey line below the title shows the first linked card and the age, such as
"#1 Checkout · 2h". An ask with more cards shows "+2" after the first. An ask
with no card shows its source, **Agent** or **Loupe**, instead. An ask that
holds an open blocking item ends the line with **Blocking**. A closed ask shows
how it ended: answered, approved, changes requested, done, dismissed, declined,
withdrawn or no longer needed.

The type filter has **All** and one icon for each type: question, review,
to-do, card wait and workflow question. Hover over an icon to read its name. A
filter keeps an ask when any of its items has that type.

Select a row to read its ask on the right. The ask takes its heading from its
first item. A grey line under the heading shows the type icon, the type and the
age of the ask, such as **Question · 3 weeks ago**. A closed ask also shows when
it closed.

A bold **From the agent** heading opens the message of the ask. An ask that
Loupe opened, such as a card wait or a workflow question, says **From Loupe**.
The items follow the message. An ask with one item shows that title once. An
ask with two or more items gives each item its own section, with a number, its
title and its type. Replies under an item show each author's initial, name and
time.

The agent's avatar beside **From the agent** carries a dot for its bridge:
green while the bridge sends heartbeats, amber when it is quiet, and grey when
no bridge started the session. Hover over or focus the avatar to read the
details and the session id.

A **Linked** panel sits beside the ask, or under it on a narrow screen. The
panel shows each card, document and pull request that the ask links, once, on
one line with its type icon. A review shows the version under review on the
document line. Select a line to open the card, the document or the pull
request.

When no ask is open, the **Open** side shows **Nothing needs you**, a line on
what agents ask here, and a link to the closed asks.

### The page follows changes

The page reads the inbox again when an agent or Loupe changes an ask while the
page is open. Every member who can open the page gets this, not only the project
owner. A new ask appears in the list, an ask that closes leaves the **Open**
side, and the counts follow. The page keeps the ask you have open, so a new
blocking ask never takes its place.

A response you were typing stays in the form. When the agent withdraws the ask
you have open, or Loupe closes it because it is no longer needed, the ask stays
on screen. A line says why and links to the next open ask. The choices and the
buttons lock, and your note stays readable.

The page does not reload while a dialog is open. It reads the inbox when the
dialog closes. This needs a Mercure hub and the `live_updates.enabled` flag.
Without them, the page is correct each time it loads.

### On a phone

At phone width the list and the selected ask take turns. The page shows the
list until you select a row. Then it shows the ask, with an **All asks** link
above it. The link returns to the list.

## Searching

Type in the search field above the list. The page then lists the asks that hold
an item whose title or body holds your words, best match first, 25 to a page.
An ask ranks by its best item. Closed asks match too, and an open item that no
open ask holds is a row of its own. The search matches whole words in the
search language of the project, so in English "exports" also finds "export".
The type filter still applies to the results.

During a search neither side of the switch is pressed. A closed ask shows how
it ended in its grey line. Select **Clear** to return to the side you were on.

## When a bridge goes quiet

An agent that a CLI bridge started names that bridge when it asks. The bridge
can resume the agent after the ask closes. Each open ask from such an agent
shows when its bridge last sent a heartbeat, in the tooltip of the agent's
avatar, on the project inbox and on the account inbox.

The dot turns amber when no heartbeat arrived in the last three heartbeat
intervals. With the default interval of 60 seconds, that is three minutes. It
also turns amber when no heartbeat from the bridge ever reached Loupe. A
running bridge keeps its interval until it reconnects or you run
`loupe bridge reload`, so the warning never waits less than three default
intervals, even after you lower the flag. While the bridge stays quiet, no resume will come. The page cannot
tell why the bridge is quiet. The machine may be asleep, the bridge may have
stopped, or the network may be down. The warning reads only the heartbeat, and
it does not check whether the bridge still follows this project.

An ask from an interactive session names no bridge, and its dot stays grey. A
closed ask shows no dot. The `bridge.heartbeat_interval_seconds` flag sets the
interval. See [Bridge heartbeat API](../reference/bridge-heartbeat.md).

## Answering

Every item that takes a response shows its forms under its text.

- **Answer a question.** The **Your answer** block lists the options, and says
  whether you pick one or any. A hovered option turns grey, and a picked option
  turns pale lime. Pick an option, write a note for the agent, or do both, as
  the question allows. Then select **Send answer**. Select **Clear choice** at
  the right of the block heading to remove your pick. The page needs JavaScript
  to send the options you pick. A question with no options shows the same
  block with one field, where you write your answer.
- **Mark a to-do done.** Select **Mark complete**.
- **Decline an item.** Write an optional note for the agent in the note field,
  then select **Decline**. Decline any item that you cannot or will not answer,
  including a question whose options do not fit. A workflow question has no
  **Decline**, because Loupe needs one of its options.

The buttons sit at the right of the item, with the main button last. Only the
project owner sees them. Another member who can see the project reads the ask
and sees no buttons.

### After you send a response

The page opens the next ask. It takes the first ask below the one you closed,
in the list you looked at. When the closed ask was the last one, it takes the
ask above it. The filter you used still applies. An ask that still holds an open
item stays open on the right, so you can answer the next item.

A line above the ask says what happened. It names the ask that closed, such as
"Answered. "Which format?" is closed.", and links to it with **View it**. When
the ask stays open, the line counts the items that are left. The line shows
once, so a reload removes it.

When no ask is left, the page shows **Nothing needs you**. The closed ask is
still on the **Closed** side.

A search or the **Closed** side keeps the ask you answered. A response sent from
a card or a document page returns to that page, as before.

Unsent question answers keep their text and selected options when you close and reopen a card drawer in the same browser tab.
A closing note also survives drawer replacement.
A successful submission clears that draft. A rejected submission keeps it for correction.
Drafts stay in memory and do not survive a reload or a closed tab.
If the request closes before you respond, its page shows your unsent answer separately from the recorded response.
Copy that draft if needed, or select **Discard draft** to clear it from this tab.

## Changing a response

Review results are final when submitted, even if no agent waits on the request.
The result keeps the verdict, note, reviewer, time, and reviewed document version.
Withdrawing a document verdict reopens document review and adds a withdrawal notice beside the original result.
It does not reopen the completed request, change its answer, or resume the agent again.

For questions and to-dos, you can change a response until an ask that holds the item closes. After that,
the response is final, because an agent may already act on it. Send a
correction to the agent in some other way.

Each closed item says which case applies:

- "You can still change this response" shows the forms again, so you can pick
  other options, mark the item done or close it.
- "This response is final" shows no forms. A change sent from an older copy of
  the page is refused with the same message.

An agent can also close an item itself, when it withdraws the item or the work
behind it finishes. Such an item takes no response from you.

## Review requests

Open the linked document or pull request before choosing **Approve** or **Request changes** in the **Your verdict** block.
In a document review, Request changes needs a note only when the version has no open comment.
A pull request review always needs a note that explains what to change.
Select **Submit review** in the inbox, or on the card that links the request.

A document submission records the same verdict as the document review page.
It completes open Review requests that target that document; questions with document links stay open.
A PR submission records a result in Loupe only. It does not post a review to the code host.

A stale form cannot replace a newer document verdict or review a changed PR address.
The error keeps your note so you can copy it before reloading.
Unsent review notes and verdict selections survive drawer replacement in the same browser tab.
They keep their original document version, previous verdict identifier, or PR address.
If that review state changes, the restored form shows a warning.
Inspect the current content and copy your note before selecting **Discard draft** to start a fresh review.
If the request closes or its target becomes unavailable, the request keeps your unsent note and verdict visible for copying or discarding.
Discard clears only this tab's draft. It does not change the recorded review.
A removed target shows an unavailable state, while completed results retain their original target label and answer.

## Automatic items

Loupe opens an item by itself when a card waits for a person. A card waits in
these cases:

- A linked document of the card is in review, and the workflow of the card
  waits for it in the column where the card sits. With the Lifecycle template,
  that is a product design, a document with the tag `product-design`, in the
  Product design column, or a tech design, a document with the tag
  `tech-design`, in the Tech design column.
- The newest worker run of the card is blocked, gave up, or waits for a person.
  The card must stay in the column that started the run.
- A GitHub pull request that is linked to the card is ready for review.
- The workflow paused the card. The wait names the reason code of the pause,
  and it ends when the pause is released. A new pause starts a new wait.

Other linked documents, such as a plan, open no wait. A change to the tags of
a document makes Loupe check each card that links it again.

The item has the kind **Waiting**, and it is always blocking.

A card has at most one open automatic item. Its title is the card number, the
reason of the main wait, and the card title, such as "#686 Review worker
blocked: cannot reach github.com · Let bridge workers read the prototypes". The
item links the card and each document that it waits on. Its panel lists each
current wait as one plain sentence. The sentence says why Loupe opened the
item, such as "The document "Tech design", version 1, of card 635 waits for
your review." A link beside it opens the page that closes the wait: the
document review, the pull request, the worker run, or the card for a pause.
A run wait starts with the first sentence of the run output, such as "The
review worker on card 686 is blocked: cannot reach github.com". When a
workflow pause of the card comes from that run, the panel shows no second
line. The link of the run line then reads "Fix the cause, then resume the
card" and opens the card. A run wait shows the whole output of the run below
the sentence, with the stage protocol text and the reason tag removed. The
body of the item that `inbox_get` returns holds the same sentences. The waits
that ended show below them, in grey, with a sentence that says how they
closed, such as "Closed when you acted on it."
**Dismiss** hides the item. The card still waits until you act on it, and the
panel says so beside the button.

The row and the panel name **Loupe** as the sender, with a magnifier icon. They
show no bridge dot and no session, because no agent session asked for the item.

The messenger worker opens and updates these items. A change on the board, on
a document or on a pull request therefore shows after a short delay.

Loupe also checks the automatic items of every project every 15 minutes. A
wait that Loupe did not see when it started or ended opens or closes its item
on the next check.

### Notices

A notice states a fact of the project. It does not block an agent and takes no
answer, and it holds no card. Loupe opened a notice while a bridge rule raced
the sync of the project. Bridge rules are gone, so Loupe closed each such
notice as **obsolete**, and opens no new one.

### Workflow questions

A workflow question comes from a rule of the board workflow, not from an agent.
Loupe opens it when a rule needs a decision about a card. Today the
`unplanned-child` rule of the Lifecycle template asks it. See
[Asking about an unplanned child](workflows.md#asking-about-an-unplanned-child).

The item has the kind **Workflow question**. It always blocks, links the card,
and names **Loupe** as the sender. It has two or more options. You pick one
option and send it. It takes no free text.

The answer is final. You cannot change it, you cannot close the item without
an answer, and an agent cannot withdraw it. Loupe runs the option you picked
after you answer. The ask that holds the item closes with your answer.

An open workflow question closes as **withdrawn**, with no answer, in these
cases:

- The rule stops holding. For example, the card links an approved tech design.
- Someone puts a hold on the card.
- Someone deletes the card.

With the inbox off, the rule opens no question. The card pauses instead.

### Pull request waits

Only a GitHub pull request gives a wait. Loupe reads the state of the pull
request, and a pull request that Loupe did not read yet gives no wait. A pull
request that is linked to two cards gives a wait on each card.

A pull request is ready for review when all of these are true:

- It is open, and it is not a draft.
- Its checks passed on its newest commit.
- It is mergeable, it is behind its base branch, or GitHub blocks the merge
  until a review.
- It needs a review, or it has no review rule. A request for changes on an
  older commit also counts, because the author pushed a fix after it.
- No worker run of the card is open.
- It does not merge with no approval. A pull request of an epic child into the
  epic branch of its parent merges there with no approval, so it gives no wait.
  The epic's own pull request into the main branch still gives one. A child
  pull request into the main branch gives one too.

An approval ends the wait. So does a request for changes on the newest commit,
a new commit whose checks did not pass yet, a merge and a close. A pull request
with changes requested waits again after a new commit with passing checks.

An approved pull request waits again when it gets new commits after the
approval. The wait says "Pull request #N of card C changed after you approved it". A merge from the base branch does not
count, with or without a conflict resolution. A force push, such as a rebase,
counts, so you approve the rewritten branch again. A new approval of the newest
commit ends the wait.

A workflow that stops asking for fixes pauses the card, and the pause opens
the wait.

### Choosing which waits open an item

Each project has seven switches, one for each cause of a wait:

- **Document in review**
- **Run blocked**
- **Run gave up**
- **Run waiting for a person**
- **Pull request ready for review**
- **Pull request fix loop stopped**, which no longer opens a wait
- **Workflow paused the card**

The switches are on the inbox settings page, at **Project settings > Inbox**.
The **Settings** button at the top of the inbox page also opens it. Only the
project owner can open the page. Every switch is on until you change it. An
agent connected to the project can change the switches with the
`inbox_settings_update` [MCP tool](mcp.md#changing-the-project-settings). A
switch that the call omits keeps its value.

When you save, Loupe checks every card of the project again. A wait that
already exists opens an item when you turn its switch on. A wait ends when you
turn its switch off. Its item closes as **obsolete** when no other wait holds
it open.

### When an automatic item closes

A wait ends when its document leaves review. A verdict does this, and so does
an archive. A new version of the document also ends the wait, and a new wait
for the new version replaces it. The item then stays open. An open review
request from an agent for the same document holds back the wait, because it
asks for the same verdict. A document wait also ends when the card leaves the
column that waits for the document, or when the document loses its tag.

A run wait ends when a newer run of the card starts. It also ends when the card
moves to another column.

The item closes when its last wait ends:

- It closes as **done** when its last wait ended by its own cause, such as a
  verdict, an archive, a newer run, an approval of a pull request or a move of
  the card.
- It closes as **obsolete** when the card finishes or someone deletes it.
- It closes as **obsolete** when you turn off the switch of its waits on the
  inbox settings page.
- It closes as **obsolete** when an administrator turns the inbox off. The
  15-minute check closes it.

The Loupe ask that holds the item closes with it.

### Dismiss

Select **Dismiss** to close an automatic item that you do not need. The item
closes as declined. Dismiss takes no note. Loupe does not open the item again
for the same document version, the same run or the same pull request commit. A
new version of the document opens a new item, and so does a new run that
waits. A pull request wait holds the dismiss for one commit: a new commit that
is ready opens a new item.

Dismiss is the only response that an automatic item takes. You cannot answer it
or mark it done. An agent cannot withdraw it, and the `inbox_withdraw` tool
refuses it.

## When an ask closes

An ask closes when you close its last blocking item. An answer, a done and a
close all count. An agent's withdraw counts too, and so does an item that
closes because every card it links to finished. An item that does not block
stays open after its ask closes, and the page then lists it among the open
items outside an open ask. A card that finishes while the inbox is off closes
no item that an agent asked, and no ask of an agent.

One item can sit in several asks. Your response then counts toward each of
them, and each ask closes when nothing in it blocks any more.

When the ask came from a session that the command-line bridge started, Loupe
asks that bridge to resume the session, and the agent reads your answers. A run
that is still open when the ask closes resumes when it ends. See
[Pause, stop and resume](../extending/cli-bridge.md#pause-stop-and-resume).
An agent reads its answers with its own session id as `readerSessionId`.

## All inboxes

Open **All inboxes** in the lower part of the sidebar to see what waits on you
in every project you own. The link is absent while the flag is off.

The page shows one row for each project you own that has an open item, in
project name order. Each row shows the first letter of the project, its name,
the number of open items, and how long its oldest open ask or item waits. A row
also shows **Blocking** when it holds an open item that an agent waits on, in an
open ask or outside one.

Select a row to open the inbox of its project, where the forms are. The page
itself is read-only.

## On a card page and a document page

An agent can link an item to cards and documents of the project. The page of
each linked card then shows an **Inbox items** section with those items. The
section is absent while the flag is off, and on a page that no item links to.

A document page shows an hourglass and the card numbers in its byline instead.
Each number links to its inbox item. Its tooltip reads **Card #N waits on this
review**, or **Cards #N and #M wait on this review** for two or more cards. The
item is absent while the flag is off, and when no card waits on the document.
Answer the item in the inbox.

The **Linked** panel of the project inbox lists linked cards, documents, and the pull requests attached to those cards.
Each card, document and pull request URL appears once per ask, even when several items or cards share it.
Web links open on the code host. Loupe does not fetch code-host status.
Other stored addresses show a note that they are not web addresses, and have no link.

A card link opens **Conversation** at the matching inbox item.
If that item falls outside the ten newest closed items, it replaces the oldest item in that list.
The section still shows ten closed items, in closing order.

The section lists open items first, then closed ones under **Closed**, newest
close first. It shows at most ten closed items, and a link leads to the inbox
page for the rest. Above each item, two lines give the context of the newest ask
that holds it. **Open the inbox** leads to the full context.

Each item takes the same forms as on the inbox page, and the same rules apply.
After you respond, you return to the card or document page you came from. A
refused response shows its message there, beside the item.

Markdown in an item or an ask renders with no heading anchors, on this section
and on the inbox page, so it never takes the anchor of a document heading.

## The projects list

While the flag is on, each row of the projects list also shows how many inbox
items the project has open.

## Turning the inbox on

`inbox.enabled` is seeded off. Open the flags page at
**`/admin/feature-flags`** and switch it on. The change needs no restart. See
[The admin area](admin.md).

The install wizard writes the flag row on a fresh install, and a database
migration writes it on an instance that upgrades. A missing row reads as off.
