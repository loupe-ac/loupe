---
name: project-site-review
description: "Use when working on the site-review widget, `public/live-review/widget.js`, `src/Module/SiteReview/`, its API routes, the dev harness or `e2e/tests/site-review/`."
---

# Site review (working on it)

A reviewer opens a widget on any web page, points at an element, and leaves a comment. The comment saves at once as feedback on a board card, and the owner's agent pulls it over MCP. There is no draft and no send step.

The Board module owns the card side. `POST /api/v1/live-review-widget/feedback` writes the card, the comment and the link in one transaction. `SiteReview` stores the comment and stays card-blind.

To consume the site-review MCP tools as an agent, see `loupe-site-review`. That job has different hazards.

## The widget is not part of the app's frontend

`public/live-review/widget.js` is a standalone, self-contained script that runs on third-party pages. The app does not build it, bundle it or style it.

Do not apply `project-frontend` to it. Its design-token rules (`@theme`, `@apply`, semantic classes in `app.css`, no arbitrary values) govern the stylesheet of the Symfony app, and they are wrong here. The widget carries its own hex-value palette in the `CHROME` / `LIGHT` / `DARK` maps near the top of the file. It injects that palette into two shadow roots, one for the panel and one for the overlay. Raw hex and literal pixel values are correct here.

Invoke `project-frontend` only for a change in `assets/` or a Twig template.

| Trap | What happens |
|---|---|
| Formatting it by hand | `just cs` runs prettier over `public/live-review/`, and `just lint` checks it. Run `just cs` before you commit. |
| Serena's edit tools | No language server is configured for JavaScript in this project. `replace_content` and similar tools fail with "No language servers available". Use Edit/Write. Serena reads still work. |
| `text-overflow` in the overlay | JS sets `display` as an inline style on several overlay nodes, and that beats the stylesheet. `text-overflow: ellipsis` has no effect on the anonymous text item of a flex container. An `inline-flex` label therefore clips mid-word and shows no ellipsis. Check the JS-applied `display` before you debug the CSS. |
| Absolutely-positioned `display` | The overlay's label is `position: absolute`, so `display` blockifies: `inline-block` computes to `block`, `inline-flex` to `flex`. The computed style does not show what you wrote. |
| Reading the platform from one source | Playwright's Chromium reports `navigator.userAgentData.platform` as `Windows` while it runs on macOS, and `navigator.platform` as `MacIntel`. The add-anchor modifier of the widget takes either Mac signal, because Ctrl+click on a Mac is a right click. The widget's own `contextmenu` handler then stands the picker down. Every pick after the first then fails, and it looks like a lost click. In a spec, mirror the same expression instead of reading `process.platform`. |

## The status model

```
Pending  →  Addressed  →  (Resolved)
```

- `Pending`: the reviewer saved it and the agent has not acted. A comment is created in this state. There is no draft.
- `Addressed`: the agent fixed it (`feedback_mark_addressed`).
- `Resolved`: a human signed it off, on the card's Feedback tab or from the widget. A card that moves into a terminal column also resolves its feedback. An agent that finishes a card with `card_update` therefore reaches this state too. No tool resolves a comment directly.

`Draft` was removed with the send step. Any reference to it is stale.

`findOnePending()` gates both edit and delete, and it fails with no signal. A wrong predicate makes rehydrate return nothing, and edits and deletes return 404. The widget still renders correctly and appears to forget everything. There is no exception and no log. Test a real edit-and-delete round trip after you touch it.

A comment is editable and deletable only while it is `Pending`. When the agent marks it `Addressed`, the reviewer's in-flight edit returns 404, and the widget says the agent picked it up. The comment leaves the reviewer's list, because the widget lists only `Pending` comments.

## Ownership voters do not constrain agents

An MCP request authenticates as the project owner. The rule of `CardFeedbackVoter` (`card_feedback.resolve` / `card_feedback.reopen`, in Board) is an ownership check on the card and the comment. Every ownership-based voter therefore returns true for an MCP call by construction.

Routing and firewall config stop an agent from resolving a comment by hand. Authorization does not. No tool calls a resolve route. `OAuthAccessTokenAuthenticator` runs only on the `mcp` and `api` firewalls. A Bearer token thus cannot reach the resolve route of the card page on `main` at all. A card move is the one path: the resolve-on-finish listeners run for a `card_update` move as for any other move.

The result of an ownership voter is thus no real check on what an agent may do. When you add a tool, do not reason "`CardFeedbackVoter` will stop it."

`App\Security\McpBoundProjectVoter` (`site_review.mcp_read` / `site_review.mcp_write`) is the one voter that does constrain an agent. The review and board modules share it, and it reaches the project of a subject through `App\Security\ProjectScopedSubject`. It compares the subject's project against the project the *token* is bound to, which is narrower than ownership. A token minted for project A cannot reach project B, even when one user owns both. Every caller-supplied site or comment id that reaches an MCP tool goes through `SiteReviewSubjectResolver`. That resolver asks that voter and nothing else. Resolve the ids of a new tool there, and do not re-derive the scope.

## A comment carries an opaque context, and SiteReview never reads it

`SiteReviewComment::$context` holds whatever the embed's `data-context` attribute said, fed by `SITE_REVIEW_WIDGET_CONTEXT`. It is null on an ordinary deployment. The template renders no attribute when the variable is empty, and the controller turns a blank value into null.

The column is a plain string and not a relation, on purpose. The value names a row that this module cannot see. `card:<uuid>` is the first shape, written by a worktree preview, and the Board module resolves it. Do not add a foreign key, and do not parse the value here. A `pr:` or `branch:` prefix must cost no migration and no change to this module.

`AddCommentHandler` dispatches `SiteReviewCommentCreated` inside its transaction, after the comment has an id. That event is the public API of the module, the same contract that `ProjectDeleting` carries. Nothing listens to it today. A listener that persists rows needs no flush of its own. A listener **must never throw**, because anything it raises aborts the comment save it was told about.

## The push subsystem has no producer

`App\Outbox\Entity\OutboxEvent`, the outbox, `DrainOutboxHandler`, the drain scheduler, both outbox pages, `ShowEventsController` and the `agent.push.enabled` flag are all present and tested. Nothing writes an event any more, because the removal of the send step removed the only producer.

The outbox now lives in the root namespace under `App\Outbox`, because it takes the events of every producer and not only those of site review. A row carries a `type`, and this module writes `SiteReviewEventType::SUBMITTED`.

The empty outbox is deliberate, because the push feature is unfinished. Do not wire a trigger without a decision, and see the card on the project board. Expect an outbox that stays empty, a "not reached your agent yet" notice that never shows, and a connected bridge CLI that receives nothing.

Counters that read `OutboxEvent` report zero forever. The projects list counts comments instead. `unsentCount` and the outbox notice stay event-sourced on purpose, because zero is *correct* there.

## API surface

Every widget route sits under `/api/v1/live-review-widget`. The old paths (`/api/live-review/...`, `/api/board/cards...`, `/api/board/feedback...`) still answer for one server release under `api_legacy_*` route names, and `WidgetApiPaths` lists both prefixes.

| Route | Method | Purpose |
|---|---|---|
| `/api/v1/live-review-widget/review` | GET | Rehydrate the widget's list (Pending only) |
| `/api/live-review/comments` | POST | Answers 410 `widget_outdated`, so a stale script tells its reviewer to reload |
| `/api/v1/live-review-widget/comments/{id}` | PATCH | Edit (Pending only) |
| `/api/v1/live-review-widget/comments/{id}/resolve` | POST | Resolve (Pending only) |
| `/api/v1/live-review-widget/feedback` | POST | Save a comment, its target card and the link in one request |
| `/api/v1/live-review-widget/feedback/{id}` | DELETE | Delete a pending comment, and the card it created while that card is untouched: still in Backlog, no other comment, its site-review type and note title, empty body, no pull request, document or card link |
| `/api/v1/live-review-widget/cards` | GET | Open cards for the widget's picker; `type=epic` lists epics alone |
| `/api/v1/live-review-widget/cards` | POST | Create a card from the widget |

This module holds two more routes that are not widget paths. `/api/projects` and `/api/events` serve the loupe CLI. `/api/events` returns the caller's own user topic and a subscriber JWT for that one topic. `DrainOutboxHandler` publishes each event on its project topic and on the user topic of the project owner. The URL and the JWT thus keep one size, however many projects a user owns. The firewall grants each route by its own rule to `ROLE_API_AGENT`. The `site-review` scope does not carry that role, so a widget sign-in gets `insufficient_scope`. The routes keep their `ShowEventsController` and `ListSitesController` classes here because their command handlers live here.

The widget routes under `/api/v1/live-review-widget` include Board routes on the `site-review` scope. `config/packages/security.yaml` grants the prefix in one `access_control` line, and grants the old `/api/board/` paths in their own lines. The grant is then visible to anyone who reads that file. `WidgetApiPaths` keeps CORS and the write rate limit on every prefix. Both listeners tested one prefix before, and a listener that tests one prefix exempts every other endpoint the widget reaches, with no signal.

The widget carries no credential. The embed names a project with `data-project`, and the reviewer signs in through an OAuth pop-up. The grant is bound to that one project, carries the `site-review` scope, and lives in the session storage of the tab. Only the owner of the project can sign in, and the page's origin must be on the project's allowed sites. `PrepareWidgetAuthorizationHandler` holds both checks.

Widget-scoped endpoints reject a token with the `agent` scope, and account-scoped endpoints reject the `site-review` scope. `/api/events` refuses it outright.

## Accepted: the site-review scope reads, edits and deletes every pending comment

The owner accepted this exposure. Do not treat it as an open finding.

- Do not re-file it as a card on the project board.
- Do not report it as a new discovery in an audit.
- Do not narrow `findOnePending()` or the CORS policy without a maintainer decision.

The acceptance covers the staging-and-preview-only deployment model and no further. Sign-in is owner-only for now, so the holder is the owner of the project. Expect the acceptance to carry more weight when sign-in widens past the owner.

`references/accepted-exposure.md` records exactly what is accepted, why it is possible and what bounds it. It also records the attribution half and the deferred per-project origin allowlist. Read it before you touch this area.

## The dev harness

`/dev/live-review-harness?email=<user>` renders a page with the widget loaded and the project reset. `&keep=1` keeps existing comments across a reload. The harness throws unless the user exists, so seed the user first:

```bash
curl -sk -X POST https://loupe.dev.localhost/dev/register-and-verify \
  --data-urlencode "fullName=Widget Preview" \
  --data-urlencode "email=e2e-site-review@example.com" \
  --data-urlencode "password=E2eSiteReview1!"
```

### Driving it in a browser

- Do not pixel-click the launcher. Its width changes with the comment-count badge. A coordinate that hit the crosshair on an empty project misses once a comment exists.
- A `keydown` on `document` does not toggle pick mode. Click the button inside the shadow root instead:

```js
const hosts = [...document.querySelectorAll('*')].filter(e => e.shadowRoot);
const pick = hosts.flatMap(h => [...h.shadowRoot.querySelectorAll('button')])
                  .find(b => b.getAttribute('data-tip') === 'Pick element');
pick.click();
```

Hover states need a real `mousemove` with client coordinates, because the widget picks its target with `document.elementFromPoint`.

## Testing

`e2e/tests/site-review/widget.spec.ts` is the real coverage. It asserts the **visibility** of the highlight and not its geometry, so padding and colour changes do not break it. `tests/Module/SiteReview/WidgetFileTest.php` asserts structural facts about the file itself.

The e2e suite needs no messenger consumer. Every message dispatched during a request that carries `X-Playwright: 1` is handled inline.

### A widget mode change does not settle in one frame

A move into or out of pick, draw or compose mode moves focus, re-renders both shadow roots, and animates the toast into its dock. A test that acts or asserts immediately after the mode change races all three.

`widget.spec.ts` carries the two races that bit. `canvasReadyAt` waits for the toast to stop and for the canvas to be the topmost node before a stroke. The toast crosses the page as it docks, and it swallows the press. The quote offer after draw mode was worse. It came back for about 15ms and then went, so the test passed on a transient until a loaded machine missed the window. That one was a widget defect, and the fix keeps the pick when the widget's own chrome takes focus.

Wait on the precondition that the next action needs, never on the mode flag. A flag often flips before the work it starts has finished.

The same rule shapes a fix in the widget. Read the promise, not the flag, and narrow to the states that deliberately break it. Four attempts at the quote guard failed because they keyed on the mode that wanted the pick kept. That flag is already false when the event lands.

Do not expire an arm by counting frames. Chromium dispatches `selectionchange` in its own task, and a frame often runs before it, even on an idle machine. The first quote fix expired its arm after two frames and lost the pick on every run. `focusKeepingPick()` now arms only when it sees the focus collapse a live selection, and the arm waits for that event. Its arrival is proven in Chromium only. A press on the page drops the arm, so a browser that sends no event cannot leave it up for the reviewer's own collapse.

Every mode exit that focuses the composer goes through `focusKeepingPick()`. That covers Done and Escape out of draw mode, a first pick, a pick into an open comment, and the end of the add-another hold. `widget.spec.ts` tests each one, plus the dropped arm. A test must wait for the widget to read the collapse, and `afterSelectionRead` does that.

A mode that owns the pointer hides the offer in `renderQuoteButton`. It must never clear the pick in `readSelection`. A `selectionchange` can land at any point during the mode, the drag's own trailing event included, and the exit promises the offer back. `state.target` sat in both lists and caused the second appearance of this race.

## Common mistakes

| Mistake | Reality |
|---|---|
| Applying `project-frontend`'s token rules to `widget.js` | It is standalone; raw hex and px are correct there. |
| Trusting an ownership voter to stop an agent | Ownership voters return true for every MCP call; `App\Security\McpBoundProjectVoter` is the one that does not. |
| Assuming a comment is private until "sent" | There is no send step. It is live on save. |
| Adding a `Draft` branch | The status no longer exists. |
| Wiring a Mercure trigger to "fix" the empty outbox | The missing producer is a decision. Do not treat it as a bug. |
