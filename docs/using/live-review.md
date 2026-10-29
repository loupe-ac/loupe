---
title: "Live review"
description: "An embeddable widget for notes on live web pages. Each note becomes feedback on a board card."
---

The live-review widget brings the select-and-comment flow to any web page. A
reviewer picks an element, or selects a passage of text, and writes a note
about it. The note saves when the reviewer presses Save. Each note becomes
feedback on a card of the project [board](board.md), and the agent reads it
with `feedback_list` or `card_get`. There is no send step.

This project uses the widget every day. The
[command-line bridge](../extending/cli-bridge.md) around it is optional and
unreleased.

## Embedding it

The embed names the project. It carries no credential, so the reviewer signs in
with a Loupe account before they write a note. The project's Connections page
shows the snippet with the project ID filled in:

```html
<script src="https://your-instance/live-review/widget.js" data-project="PROJECT-ID"></script>
```

On the hosted instance, the script address is `https://app.loupe.ac/live-review/widget.js`.

The ID is the project's UUID, the same one that is in the project's URLs. The
widget reads its backend from its own `script.src`, so the host that serves the
script is the instance it talks to.

Install it on staging and preview environments only. Only the owner of the
project can sign in. On a public page, no visitor can use
the launcher.

### Allowing a site and signing in

1. On the Connections page, add each site that embeds the widget to **Allowed sites**.
   Write one origin on each line, such as `https://staging.example.com`, with no path.
   Use `https`. Plain `http` works for `localhost` only, because the widget needs a secure page to sign in.
   The first label can be a `*`, which covers one level of subdomain: `https://*.example.com` covers
   `https://staging.example.com`, and it covers neither `https://a.staging.example.com` nor `https://example.com`.
   The scheme and the port must still be the same. Loupe refuses a bare `*` and a wildcard over a whole
   registry, such as `*.com` or `*.co.uk`, because one entry would then cover every site under it.
   A preview environment gives each branch its own host, so it needs a wildcard.
2. Paste the snippet into the site.
3. The reviewer opens the widget and presses **Sign in with Loupe**. A pop-up window opens on your Loupe instance.
4. The reviewer signs in, checks the project and the site on the consent page, and presses **Allow**.

The pop-up sends the answer back only to a site on the allowed list, so a copied
snippet does not work on another site. Only the owner of the project can sign in
for now. Loupe refuses any other account on the consent page.

The widget keeps its tokens in the tab's session storage, so a new tab signs in
again. It renews its access in the background. When the renewal fails, the
widget shows the sign-in button again. This happens, for example, after you
revoke **Loupe live-review widget** under **Connected apps**. Removing a site
from the list stops new sign-ins from that site. Revoke the app to end a sign-in
that already exists.

A pop-up blocker must allow pop-ups for the site. Sign in to Loupe with a
password in the pop-up. A social sign-in provider can cut the link between the
pop-up and the page, and the widget then gets no answer.

## Where notes go

Every note lands on a card. Until you choose where notes go, the panel opens on
a view named "Where should your notes go?", and so does the first note. Choose
one option and press **Start reviewing**:

| Choice | What a save does |
|---|---|
| A new card for each note | Creates a new card for the note. |
| One card for this review | Adds the note to one card. Create a new card, or pick an existing one. |
| A card for each note, under an epic | Creates a new card for the note, as a child of one epic. Create a new epic, or pick an existing one. The widget shows this choice only when the workflow template has a card type with the children capability. |

The widget keeps the choice in the browser's local storage, for this instance
and this project. It holds for every later note, and after a reload, until you
change it. The panel header shows where notes go. Click it to open the
destination view and choose again. The line "Saves to ..." under the title of the
composer does the same. Once a choice exists, the button of the view reads
**Save**.

The destination also decides which notes the list shows:

| Choice | What the list shows |
|---|---|
| A new card for each note | The notes that you saved on the cards this browser tab made. A new tab starts with an empty list. |
| One card for this review | Every open note on that card. |
| A card for each note, under an epic | Every open note on the cards under that epic. |

When you change the destination, the list changes with it. Until you choose a
destination, the list is empty. With **One card for this review**, the footer
shows **Open #N in Loupe**, which opens that card.

The first choice opens a switch with **New card** and **Existing card**. The
third choice puts the epic on a second step: press **Next**, and the view
shows "Pick or create an epic" with the same switch, as **New epic** and
**Existing epic**. The back arrow returns to the choices. With **New card**, type a title and the button creates the card. The
title starts as `Review: ` and the page path, and the view selects it so that
you can replace it. With **Existing card**, search the open cards, pick one
row and press the button. The third choice lists the open cards of every type
that has the children capability, and it creates a card of one of those types.
When the template has two or more such types, a **Type** selector shows under
the title. The first type of the template is chosen at first.

A card that the widget creates has these values:

- The type is the default type of the workflow template for a note card or a review card, and the type with the children capability for an epic.
- The column is the board's Backlog.
- The reporter is `reviewer`.
- The source is the live review widget. The card shows a live review badge.
- A note card takes its title from the start of the note, cut at a word to 80 characters, with "…" at the end when the note is longer.
- The card body holds the whole note.
- When you edit the note while the card sits in Backlog and nobody has changed it, the title and the body follow the edit. After that the card keeps its own text.

Each save shows one short notice that names the card, such as "Note saved to #12 Review: /pricing". An edit names the card too, such as "Note updated in #12 Review: /pricing". When you edit a note, the composer says that only the text can change.

When the card or the epic you chose is closed or deleted, the widget forgets the
choice and asks again. A card in a terminal column is closed. Your draft stays.

### A preview page locks the card

A preview deployment can say which card it serves. It sets
`SITE_REVIEW_WIDGET_CONTEXT` to `card:` followed by the card id, and the embed
carries that value in `data-context`. See
[Environment variables](../reference/environment.md).

On such a page, every note goes to that card. The widget does not ask for a
choice, and it does not change the choice you keep for other pages. When the
card is closed or deleted, the widget refuses notes and says so.

The list shows only the open notes on that card. The panel header names the
card. The second line gives the pull request and its last verdict, for example
**PR #88 · Changes requested**, or **No pull request yet** with the number of
notes on the card. The footer has one action. It is **Finish review** when the
card has an open pull request, and **Open #N in Loupe** when it has none.

## Preview sites

A preview page can sign the widget in with no popup. It sets
`SITE_REVIEW_WIDGET_PREVIEW_TOKEN`, and the embed carries the value in
`data-preview-token`. See [Environment variables](../reference/environment.md).

```html
<script src="https://your-instance/live-review/widget.js" data-project="PROJECT-ID" data-preview-token="TOKEN"></script>
```

The widget sends the token as its sign-in. It shows the review tools at once,
with no pop-up and no consent page, also in a new tab. The token never renews,
and it ends after 14 days at most. The token acts as the person who minted it,
so a note shows that person as its author.

A sign-in through the pop-up wins over the token. The widget keeps that sign-in
in the tab's session storage, as described above. When that sign-in ends and
cannot renew, the widget uses the token of the page again.

When the token ended, the widget shows this line: "The preview sign-in for this
page ended. Sign in, or restart the preview." Under it, the **Sign in with
Loupe** button works as before. A token ends when its preview stops, when its
card reaches a terminal column, or when the owner revokes it.

The page source carries the token. Use it on internal preview pages only. The
allowed sites of the project do not limit where a token works.

### Minting and revoking a token

The `loupe preview-token` command mints and revokes these tokens. It works for
any preview setup. For example, a CI job can mint a token for each preview
deploy, pass it to the page as `data-preview-token`, and revoke it when the
deploy goes away.

```bash
loupe preview-token mint --project <id> --label <preview> [--card <cardId>]
loupe preview-token revoke --project <id> --label <preview>
```

`mint` prints the token on one line. With `--card`, the token also carries the
card that the preview shows. The label names the preview, such as a branch or a
worktree. A token lives 14 days at most.

A second `mint` with the same label revokes the first token. `mint` exits with
status 1, and prints the reason on stderr, when the server refuses. `revoke`
exits with status 0 also when no live token has the label.

`--instance <url>` makes the command refuse unless you are signed in to that
instance. Use it when a script must never mint on another instance.

A project owner sees and revokes every token under Account > Connected apps. To
set up the CLI, see [Installing the CLI](../getting-started/cli.md).

## Sending a verdict

On a page that locks a card, the panel footer shows **Finish review** when the
card has at least one open GitHub pull request. It opens one view that holds
the whole verdict form. The footer action stays hidden on every other page.
That includes a page that names no card and a card without an open pull
request.

The view opens with a verdict already chosen. It picks **Request changes** when
the card has open notes, and **Approve** when it has none. You can pick
**Approve**, **Request changes** or **Comment** instead. The message field has
focus, so you can type at once. Loupe records nothing until you press **Send review**.
Press **Cancel** or the back arrow to return to the list with no record.

The view shows:

- The name of the card and its pull request, under the title.
- The pull requests, when the card has two or more. You tick each one that gets
  the review. Send review covers the ticked pull requests.
- The open notes, under **Notes in this review**. On an approval, the heading is
  **Notes still open**, and you can still send.
- A field for a message.
- What Loupe does with the verdict. When the workflow writes nothing to GitHub,
  the view says **Recorded in Loupe only**.
- The GitHub account that posts the review, as **Posts to GitHub as @name**,
  when you have connected one.

**Request changes** and **Comment** need a message when the card has no open
note. **Send review** stays off until you write one. When notes are open, they go with
the verdict, and the message is optional. **Approve** needs no message.

After a send, the panel returns to the list and shows **Review sent to GitHub**,
with the verdict, such as **Review sent to GitHub: changes requested**. When the
verdict posts no review, it shows **Review sent** instead.
Loupe then posts the review on GitHub when the project allows it. See
[Board automation](board.md).

When a send fails and you press **Send review** again with the same verdict, Loupe
keeps one verdict. It does not post the review twice. If you change the
message or the ticks first, the panel sends a new verdict.

### Connecting GitHub

Loupe posts a review under your own GitHub account. When you have not
connected that account, or the connection ended, a **Connect GitHub to send**
button replaces **Send review**. The button opens a GitHub window. Allow
pop-ups for the page if your browser blocks it.

When you return, the panel reads your connection again. Your message and ticks
stay. If you close the window or the connection fails, the draft stays too.

### Your own pull request

GitHub accepts no approval and no change request from the author of a pull
request. When a ticked pull request is yours, the panel says so, and the review
goes as a comment.

### The result

The panel shows the last verdict of the card with its time. Each pull request
has a line that says one of these:

- **Waiting to post**: Loupe has not posted the review yet.
- **Review posted** or **Posted as a comment**: the line links to the review.
- **Not posted**: the line gives the reason. For example, the pull request is
  no longer open, or the project does not post reviews.

When your GitHub connection ended, the line says so and offers **Connect
again**.

### What the review says on GitHub

Every review that Loupe posts opens with one line. The line says that the
review comes from the Loupe live review of the card, and links to the
**Feedback** tab of the card. It also links to the page of the first note, when
the review has a note. Only members of the project can open the card link. The
message and the notes follow the line, and an approval with no message posts
the line alone.

## Retrying a save

A failed save keeps the draft open. Press **Save** again to retry it.
The widget keeps the submission ID until you save successfully or cancel the draft.
The server can save the note and lose its response. An unchanged retry then returns the same note.
It creates no second note and no second card.

If you change the content after the server accepts it, the retry reports a conflict and keeps your draft.
Copy the draft before you reload, then review the saved note.
A reload or a cancel starts a new submission. It does not undo a note that already reached the server.

The widget saves through `POST /api/board/feedback`. The request takes an optional UUID in `deliveryId`.
A client must reuse it with unchanged content when it retries.
Its scope is one project, and it lives as long as the stored note.
Reusing it with different content returns HTTP 409 with `delivery_conflict`.

The request can carry a note from a native app instead of a web page.
Set `surface` to `ios-simulator` and send the facts in `native`, with no `url`.
A web note keeps `surface` as `web` and sends `url`, and it sends no `native`.
Either kind can send `screenshot` with `contentType` and base64 `data`.
The image must be a JPEG or a PNG of 2 MiB or less when decoded.
A larger image gets HTTP 422 with `screenshot_too_large`.
Any other file gets HTTP 422 with `screenshot_invalid`.
A retry that changes the surface, a native fact or the image returns `delivery_conflict`.

`GET /api/live-review/review` lists the notes of the web surface.
Pass `surface=ios-simulator` to list the notes of the native surface.

An old copy of the widget script saves through `POST /api/site-review/comments`.
That path is gone and answers HTTP 404, so the old copy cannot save a note.
Load the script from `/live-review/widget.js` to get the current widget.

## Resolving a note

Open the menu of a note and choose **Mark resolved**. The menu button is the
three dots at the end of the row in the widget's list. The popover that opens
from the pin of a note also has a tick. The note leaves the widget, because the
widget lists the notes that are still open.

Resolving keeps the note. It shows as **Resolved** on the Feedback tab of its
card, where you can read it again and reopen it. The Feedback tab also has
**Resolve** for an open note.

A card that finishes resolves its feedback. When a card moves into a terminal
column from an open one, every pending or addressed note on it becomes
resolved. A column delete that moves cards from an open column into a terminal
column does the same, and so does marking a column that holds cards as
terminal. A move back out of the
terminal column leaves the notes resolved.

## Deleting a note

Open the menu of a note and choose **Delete note**. The widget deletes a pending
note, and it asks you to confirm first. The confirm slides over the row, with
focus on **Delete**. Press Esc or **Cancel** to keep the note. The
delete also removes the note's card when all of these are true:

- The note created the card.
- The card is still in Backlog.
- The card holds no other feedback.
- The card keeps the default type and the title that the note gave it.
- The card body is empty.
- The card has no pull request, no document and no link to or from another card.
- No session or worker has an open run on the card.

A card that somebody moved, edited or linked, or that holds other notes, stays. Deleting a card
from the board deletes all of its feedback.

## Quoting a passage of text

Open the widget, then select text on the page as you normally do. A **Comment
on this text** button appears under the selection. Click it, and the composer
opens with that passage quoted. The widget does not pick the whole paragraph.
The selection can run across bold, links and other inline markup.

The offer appears only while the widget panel is open, so ordinary reading and
copying on the page do not change.

A quoted anchor behaves like any other. It gets a pin, an outline around the
words, and a pill in the composer. One note can hold both kinds. You can quote
a sentence and pick a button in the same note, and say that the two disagree. A
quote holds up to 1000 characters. The widget refuses a longer selection and
tells you so. It never stores part of it.

The agent receives the quoted text, so "this sentence is wrong" arrives with the
sentence.

### When the page changes under a quote

The widget stores the quoted words and a little of the text on each side, and
finds them again on your next visit. The text around the quote tells two
identical phrases in one paragraph apart.

If somebody edits or removes the words, the note stays. The anchor falls back to
the element that held the quote. The pin stays, and the outline grows from the
passage to the whole element. Only the precision goes.

## Pointing one note at several elements

Pick an element, then hold **⌘** (**Ctrl** on Windows and Linux) and click
another. The picker stays up while you hold the key, so you can add several
elements at a time. You can also hold the key before the first pick. The
composer shows one pill for each element. A note can hold up to ten elements.
Save once, and the note is about all of them.

Point at a pill, and the widget shows the element that the pill names more
strongly. The other elements stay outlined. Click the pill to scroll to its
element when it is off the screen. Two controls remove an element: the × on its
pill, and the × on the element's own outline when you point at it.

Use this when the feedback is about a relationship. "These two should sit side
by side" is one note about two elements.

Every element gets its own pin on the page, and every pin of one note carries
that note's number. Point at one pin, and the widget outlines every element of
that note. That shows that they belong together.

When you come back to a page and one of a note's elements is gone, the widget
marks the note as degraded. The pins that remain get an amber dashed border.
The popover says how many elements are missing, and the list row reads
"1 of 2 elements". A note on a single element that no longer matches shows no
pin.

## Drawing on the page

**Draw** is the third way to capture something, beside picking an element and
writing a page note. Press it in the panel, press the pen on the launcher
without opening the panel, or press **D** with the panel open. Then drag on the
page to draw. Every drag adds a stroke. **Undo** removes the last one,
**Clear** removes the whole drawing, and **Done** (or **Esc**) puts the caret back
in the text box. Your draft and your elements stay where they are.

A stroke creates no anchor. A drawing over an element does not point the note
at it, and an arrow that ends on a button does not attach to that button. Pick
the element with the picker when you want the note anchored, then draw. One
note can carry elements and a drawing together. That is how you say "move this
box over there".

Where the drawing goes when the page changes depends on the note:

- A note with an element stores its strokes as fractions of the first element's
  box. The drawing moves and resizes with that element. It survives a window
  resize and a responsive breakpoint.
- A note with no element stores its strokes as fractions of the document width.
  The drawing survives a scroll and a reload. It does not follow a reflow. It
  scales with the page width and stays where the page put it, so content that
  moves leaves the drawing behind. Anchor the note to an element when that
  matters.

The first release has no eraser for one stroke, so Undo and Clear are the only
ways to take a stroke back. An edit of a saved note changes its text only. The
drawing and the elements stay as you saved them.

Your agent learns only **that** a note carries a drawing. It does not get the
drawing itself, because it cannot draw vector points over a live page. Discuss
the drawing with the agent, and put the point in words too.

Drawing is behind the `site_review.drawing.enabled` feature flag, which is on
after an install and after an upgrade. Turn it off in `/admin/feature-flags`,
and the widget removes **Draw** from the panel and from the launcher. The API
then refuses a drawing, and it does not save the note without it. Saved
drawings still show on the page, so the switch removes the tool and keeps the
work.

## Moving the launcher

The launcher sits in the bottom-right corner, where many pages pin their own
controls. Move it to another corner in one of two ways:

- Open the panel, open the menu in its header and choose **Move launcher**. Each
  choice moves the launcher to the next corner, and the item names that corner.
  Tab reaches the menu button, and Enter or Space opens the menu.
- Drag the launcher. It follows the pointer, and on release it snaps to the
  nearest corner. A drag never opens the panel.

The panel, the composer and every toast follow the launcher, so every corner
keeps them on the screen. The widget keeps the corner in the reviewed page's
own browser storage, so it survives a reload. A private window, or a browser
that blocks site data, gets the bottom-right corner every time. The widget
works the same way otherwise.

The widget also sets `data-loupe-review-corner` on the page's `<html>` element,
beside `data-loupe-review-open`. A page can read either one to move its own
pinned controls out of the way.

## What it needs from the page

The widget needs very little. It is a `fetch` with a bearer header. It uses no
clipboard, no cookies, and no browser API that needs a secure context. It
writes two localStorage keys: `loupe.site-review.corner` for the corner, and
one key that starts with `loupe-site-review:mode:` for where notes go. It works
without them. Cross-origin embedding works because the API answers CORS itself.

So you can serve Loupe over plain HTTP when the reviewed page is also plain
HTTP. The doubtful case is an **HTTPS** page that embeds a Loupe on
`http://localhost`. Mixed content rules exempt localhost, but Chrome's
private-network rules can still block the request. Nobody tested that case. See
[Reverse proxy](../extending/reverse-proxy.md).

## What the reviewer sees, and for how long

The widget lists the notes that still wait on the agent, in the context of the
panel: the locked card, or the destination of your review. A note stays in that
list, where you can edit and delete it, until the agent marks it addressed.
Then it **disappears from the widget**. The worker that builds a card marks the
notes that its build covers, after it checks the fix on the rendered page. A
child of an epic marks the notes of its epic in the same way. A note that the
worker cannot check stays open for you, and the next verdict copies it.

The list is always open. It has no timestamps. Each row has one menu button.
Its menu holds **Edit text**, **Mark resolved** and **Delete note**. With **A
new card for each note**, the menu also starts with **Open #N in Loupe**, which
opens the card of that note. The menu
opens upward when the row is near the foot of the list, and it puts focus on
its first item. The notes made on the page that
you are looking at come first, under **On this page**. The notes made on other
pages follow under **Other pages**, and each of those rows has a link with the
path of its page. Pins on the page carry the same numbers as the list. The
count on the launcher, the pins and **Delete all** read this one list, so
**Delete all** never reaches a note outside the context.

The menu in the header holds **Move launcher**, **Sign out** and **Delete all**.
**Sign out** shows only when you signed in with the Loupe pop-up. It ends the
sign-in of this tab and shows the sign-in view. **Delete all** asks you to
confirm in the footer. The question names the number of notes and, when the
panel has one card, the card. Click outside a menu, or press Esc, to close it.
Esc closes the menu before it closes anything else.

The panel shows one view at a time. The composer, the destination choice and
the verdict form slide in over the list, and the back arrow slides them out.
The panel slides up to open and down to close, and a message slides in from the
edge of the page and back out. With the system setting that asks for reduced
motion, the views and the messages swap with no slide. The closed launcher keeps the **Review** button with the count and three
tool buttons: add a note, pick an element and draw. The tool in use shows with
the accent fill. When you are signed out, the launcher says **Sign in to
review**, and the panel explains the three steps with a **Sign in with Loupe**
button.

That is the intended lifecycle. The note is still on the Feedback tab of its
card, where you check the fix and resolve it. So the widget is a list of open
feedback. It is not a record of everything you said, and a note can disappear
while you look at the page.

If you edit a note at the moment the agent picks it up, the widget refuses your
save and tells you so. It does not discard your text without a word.

## Reviewing an iOS app in the simulator

You can review an iOS app that runs in the iOS Simulator. The Live review panel is a small macOS app. It sits beside the simulator and saves notes to your project.

You need these things:

- A Mac with Xcode 27. The panel reads the simulator in the Device Hub window of Xcode 27.
- The `loupe` CLI, installed on macOS with the install script or Homebrew. [Installing the CLI](../getting-started/cli.md) gives the steps. The macOS install brings the panel, and `loupe update` brings the panel of each new version.
- A booted simulator with your app, open in Device Hub.
- The owner role in the project. A member who is not the owner sees "Only the owner of this project can review it."

To start a review:

1. Open a terminal in the folder of your app.
2. Run `loupe review ios`. The command opens the panel and prints `Opened Live review for <folder>.`
3. Allow the permission. On the first run, macOS asks for the Accessibility permission for **Live review**. Open System Settings, then Privacy & Security, then Accessibility, and tick **Live review**. The panel updates by itself. It reads the Device Hub window only.
4. Choose **Sign in with Loupe**. The panel shows a code. Check that the browser page shows the same code, then choose **Allow**. A login from before this release needs one more sign-in, because the login now carries the `site-review` scope.

The project comes from `.loupe.yaml` in the folder. [`loupe project link`](../reference/cli.md#loupe-project-link) writes it. Without that file, the panel lists your projects and asks which one the notes belong to. It remembers your pick for that folder.

To leave a note:

1. Choose **Pick element** or **Draw**.
2. In **Pick element**, click an element in the simulator. The panel marks the element and shows its role, its label and its identifier at once. A spinner shows while it takes the screenshot. The note field then has the focus.
3. In **Draw**, draw on the simulator screen. You can draw more than once for one note.
4. Choose **Navigate** to use the simulator as usual, for example to scroll or to tap. Hold Option to do the same for a moment in the other modes.
5. Type the note.
6. Choose **Save**.

The panel marks the simulator only while Device Hub, or the panel in front of it, is the active window. Switch to another app and the marks go away until you come back.

When nothing at the point has an accessibility element, the panel says "Nothing to pick here." and offers **Draw**.

The **Saves to** menu works as [Where notes go](#where-notes-go) says. It starts on **One card for this review**. The first save creates that card, and the later notes of the review join it. You can pick **A new card for each note**, a card for each note under an epic, or an existing card.

The card page shows the note with a thumbnail of the screenshot. Choose the thumbnail to see the image at full size. The image holds your marks: the box of the element you picked, or your drawing. The note also lists the device, the iOS version, the app, the name of the screen, and the role, label and identifier of the element.

The panel takes the name of the screen from the first heading on the screen. Else it takes the first text in the navigation bar. An app that exposes neither gives a note with no screen name. Your agent reads these facts as [Reaching your agent](#reaching-your-agent) says.

The panel works with the [`loupe review ios`](../reference/cli.md#loupe-review-ios) command. A Linux install has no panel, and the command stops there.

## Reaching your agent

Notes do not push. Your agent sees them when it calls `feedback_list` for the
whole project, or `card_get` for one card. Ask it to look. Nothing arrives
without a request.

A note from a native app has a `surface` of `ios-simulator`, `native` facts and
no page `url`. A note with a screenshot sends the image after the JSON. An answer
carries at most ten images, newest first. Read more with `feedback_list`, using
`cardId` and `imageOffset`. See
[A feedback item says where it came from](mcp.md#a-feedback-item-says-where-it-came-from-and-may-carry-an-image).

A note carries no author, so an agent cannot tell your notes from anyone else's
on the page. A card that the widget creates does not make its note trusted
either. The shipped `loupe-site-review` skill therefore escalates by category.
It sends to you any note that would change a destination, an identity, a
credential or third-party code, and it does not act on it. For the same reason,
the widget belongs on staging only.

Live push over a Mercure hub, an outbox for undelivered events, and the
[command-line bridge](../extending/cli-bridge.md) are all still present, and
they do nothing now. Nothing publishes an event, so the outbox stays empty, and
the per-project and `/admin/outbox` pages have nothing to show. That part of the
feature is not finished. Pull with `feedback_list`.
