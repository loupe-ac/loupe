---
title: "Mercure hub"
description: "Optional. Needed for live-review push and for live pages across the app; without it, submissions still save and pages update on the next load."
---

Live-review push and the live pages need a Mercure hub. The live pages are the
board, the documents, the artifacts, Insights, the project and account pages,
the activity page, the worker run lists and the inbox count. Leaving the hub off
is a supported state: submissions reach the outbox first and a scheduled drain
replays them once a hub exists, so nothing is lost — but nothing reaches a
running agent either, and the publish failure is only logged. An open page shows
the changes of other people on its next load. It degrades silently rather than
erroring.

The hub is in-memory. A restart drops undelivered updates, which is exactly why
submissions are recorded in the `outbox_events` outbox and the bridge
resumes from `Last-Event-ID`: delivery is best effort, replay is not.

## Configuration

| Variable | Purpose |
|---|---|
| `MERCURE_JWT_SECRET` | Shared HS256 key, minimum 32 characters, **identical for the app and the hub**. No default ships — unset means Mercure fails loudly rather than signing with a publicly-known key. |
| `MERCURE_URL` | Where the app POSTs updates — the hub on the internal network. |
| `MERCURE_PUBLIC_URL` | Where clients subscribe. A genuinely separate host, since the bridge CLI reaches it directly, so it cannot be derived from `DEFAULT_URI`. |

Two feature flags use the hub, and each switches on its own:

| Flag | Switches |
|---|---|
| `agent.push.enabled` | The outbox drain, the bridge CLI's subscriber credentials at `GET /api/v1/bridge/events`, its catch-up read at `GET /api/v1/bridge/events/replay`, and its worker-run reports at `PUT /api/v1/bridge/projects/{handle}/worker-runs/{runId}`, and `PUT /api/v1/bridge/bridges/{bridgeId}/runs`. |
| `live_updates.enabled` | Live updates in the browser: the subscriber cookie, the page element, `POST /mercure/authorize`, every publish of `LiveUpdatePublisher`, and every live page: the live regions, the lost-access band, the worker run lists, the inbox count in the sidebar, and the inbox page. |

Both flags require all three variables. With any of them blank, the flag reads
as off whatever the admin page stores. Both ship on. An instance that upgrades
gets `live_updates.enabled` with the value its `agent.push.enabled` had, because
that flag switched live updates before.

## In development

`just mercure-up` starts the hub behind a compose profile; `just mercure-down`
stops it. The e2e suite passes without it.

If you serve the app without a reverse proxy, note that `MERCURE_PUBLIC_URL`
belongs in **`.env.dev.local`**, not `.env.local`: `.env.dev` pins it to the
Traefik host and outranks `.env.local`, so a value set there is read and then
discarded. `bin/console debug:dotenv` prints the precedence.

## Listening from a page

A page opens one connection to the hub, whichever features on it listen. The
server decides which topics the connection carries, and the browser never names
a topic it was not given.

A feature that wants live updates on a page adds three things:

1. A class that implements `App\Mercure\MercureTopicAuthorizerInterface`. It
   returns `null` for a topic its module does not own. For a topic it owns, it
   returns whether the current user may subscribe. `BoardTopicAuthorizer` is the
   example, and it applies the project view permission. `InboxTopicAuthorizer`
   allows a user only their own `/users/{id}/inbox` topic, while the inbox is on.
   `InboxPageTopicAuthorizer` allows the `/projects/{id}/inbox` topic to whoever may view the project, while the inbox is on.
   `NotificationTopicAuthorizer` allows a user only their own `/users/{id}/notifications` topic, whatever the inbox flag says.
   `DocumentsTopicAuthorizer`, `ArtifactTopicAuthorizer` and `AnalyticsTopicAuthorizer` apply the project view permission to the documents, artifacts and analytics topics of a project.
   `AccountTopicAuthorizer` allows a user only their own account topic.
2. A call to `{% do mercure_subscribe(topic) %}` in the page template. A
   topic that every page of a project needs belongs in
   `_mercure_project_topics.html.twig` instead. A connection stays open across a
   Turbo visit only when it carries every topic of the next page, so a project
   page that asks for a topic outside that set reconnects on each visit.
3. A region that uses the shared `live-region` controller. Most pages need
   nothing more, and [Making a region live](#making-a-region-live) describes
   it. A page with special needs adds a handler in its own Stimulus
   controller, with `on(types, handler, options)` from `assets/lib/live.js`. A
   handler receives each message whose JSON `type` it names. See
   [The browser layer](#the-browser-layer).

The layout asks every authorizer about the requested topics. It writes one
`mercureAuthorization` cookie that holds the allowed topics, and it renders them
into `#mercure-subscriptions`. A topic that no authorizer claims, or that its
authorizer refuses, never enters the token.

Before it reconnects, the page posts its topics to `POST /mercure/authorize`.
That endpoint runs the same authorizers, drops what they refuse, and writes the
cookie again. It takes a CSRF token and allows 30 renewals a minute per user.

Keep the message `type` in the JSON body of an update. `Update::$type` renames
the SSE event, and the page listens for `message` events only.

With the hub unconfigured or `live_updates.enabled` off, the layout renders no
element, and a page opens no connection. A renewal then answers an empty topic
list and sets no cookie. `agent.push.enabled` has no effect on a page.

## Making a region live

Most pages need no JavaScript of their own. A page marks a region, and the
`live-region` Stimulus controller keeps it current. The loop is the same for
every region:

1. A writer commits a change and queues a signal that names only ids.
2. The open page receives the signal. It skips a signal that it caused itself.
3. The region waits for 300 ms of quiet, and at most 2 seconds. It waits longer
   while a drag runs, a dialog is open, or a field in the region holds typed
   text.
4. The region reads its `url`, with the header `X-Loupe-Live-Refresh: 1`. The
   server renders the answer for the current viewer, and the voters decide what
   the viewer sees.
5. The region applies the answer by its mode.

Declare the region in the template, and subscribe the page to the topic with
`mercure_subscribe()`:

```twig
{% do mercure_subscribe(topic) %}
<turbo-frame id="documents-live" refresh="morph" target="_top"
    data-controller="live-region"
    data-live-region-types-value="{{ ['documents.changed']|json_encode|e('html_attr') }}"
    data-live-region-mode-value="frame"
    data-live-region-url-value="{{ path('app_project_documents', {id: project.id}) }}">
    {# the list markup #}
</turbo-frame>
```

The answer of the route must hold a frame with the same `id`. The region
finds its content by that `id`.

| Value | Meaning |
|---|---|
| `types` | The signal types that the region reacts to. |
| `url` | The route that renders the answer. |
| `mode` | One of the four modes below. The default is `frame`. |
| `match` | Payload keys that must equal given values, such as the id of one artifact. |
| `quiet`, `max` | The wait of the region in milliseconds, when the defaults do not fit. |

The four modes:

| Mode | The answer | Use it for |
|---|---|---|
| `frame` | A `<turbo-frame refresh="morph">`. Unchanged rows keep their DOM, focus and scroll. | A list or a panel. |
| `stream` | A Turbo Stream. | One band, one comment thread or one status chip. |
| `rows` | Turbo Streams for page 2 and later of a list. The read sends the ids of the shown rows and the cursor of the page. | A paginated list. A row that no longer matches fades out. |
| `band` | No read on a signal. The region shows a band at once, with a link that opens the subject in a new tab, so typed input stays. After a reconnect, it reads its `url` and compares the answer with `data-live-fingerprint`. | An edit form whose subject another actor can change. |

Three rules hold for every region. Never morph the text of a document, the
prototype frame of an artifact, or a form with typed text. A list keeps its
page and its row order stable, and each shown row can still morph in place. Never use a page refresh for a live update. A button
that loads new content is a plain link.

The region retries a network error, a 5xx, a 408 or a 429 after 1, 3 and 9
seconds. Then it shows a "stopped" band, and the page needs a reload. A region
also reads again after each reconnect, because the hub keeps no history. A band
region reads its `url` then, and shows the band when the answer differs from
`data-live-fingerprint`. A band region that has no `url` shows the band after
every reconnect.

The wait, hold and retry logic lives in `assets/lib/live_fetch.js`. A page with
special needs calls it from its own controller, and never copies it.

## When a page loses access

A page that stays open can lose its access: the session ends, the account is
suspended, the project is deleted, or a paywall starts. A live read must not
follow the redirect to the login page, because that answers 200 with the wrong
page. `AnswerLiveRefreshOnLostAccess` acts only on a request with
`X-Loupe-Live-Refresh: 1`:

| Cause | Answer |
|---|---|
| No session, or a suspended account | 401 with `X-Loupe-Live-Lost: signed-out` |
| A project route whose project no longer exists | 410 with `X-Loupe-Live-Lost: project-deleted` |
| An account with no active subscription | 402, and the region shows the stopped band |

`handleLostAccess(response)` in `live_fetch.js` takes such an answer. It hides
each `[data-live-content]` element, stops every live region, and shows one
warning band with the cause. A sign-out band also holds a **Sign in** link.
Every live controller sends the header and passes each 401 or 410 to that
function.

A project deletion queues `project.deleted` on the worker run topic of the
project. Every page with the project sidebar, and the full-screen artifact page,
hears it. A page that names its project in `data-live-project` reads itself
once, gets the 410 and shows the band. A sign-out sends no signal: the next live
read, or the next renewal of the subscription, gets the 401.

## Publishing a live update

Send every browser update through `App\Mercure\LiveUpdatePublisher`. Call
`queue(topic, payload)` after the change commits, so a rollback sends nothing.
The payload is a flat array of scalars, and it must hold a `type`.

The publisher keeps the updates until the work is done. It publishes them after
the response is sent, after a messenger message is handled, or at the end of a
console command. A hub that hangs therefore never slows a change. The same
update queued twice in one request goes out once.

The publisher adds an `origin` to each payload that has none. The value is the
`X-Loupe-Origin` header of the request, which the browser layer sets on each
Turbo request. A page compares it with its own id to tell its own changes.

A signal waits for its change to commit. The publisher holds each signal while
a database transaction is open, and drops it on a rollback. Queue from an event
listener or a Doctrine listener as you like: the signal goes out only after the
commit. A change that flushes with no explicit transaction queues from a
Doctrine `postPersist`, `postUpdate` or `postRemove` listener, because Doctrine
runs those inside the flush.

The publisher reads `live_updates.enabled` when it publishes, and sends nothing
while the flag is off. A failed publish is only logged, as
`live_updates.publish_failed`, and the change stays saved.

## The browser layer

`assets/lib/live.js` sits on top of the Mercure client. A controller imports
it and never talks to the hub itself.

| Function | What it does |
|---|---|
| `on(types, handler, options)` | Calls `handler` for each change of those types, from the hub or from `emit`. Each change carries `local` and `own`. `own` is true when the `origin` is this page. It returns a function that removes the handler. |
| `emit(type, detail)` | Gives a change that this page made to its own handlers at once, with `local` and `own` true. It works with no hub. |
| `status(listener)` | Calls the listener at once and on each change with `live`, `paused` or `off`. The state is `paused` after the connection stays down for 5 seconds. |
| `originId()` | The id of this page, which the `X-Loupe-Origin` header carries. |

The hub keeps no history. A message sent while a page is not connected never
reaches that page. Pass `onReconnect` in the options of `on`, and fetch the
current state there. The board reloads its frame, and the worker run lists
fetch again.

Treat `own` as a hint only. A member can send the origin of another tab, so
`own` may hide a highlight, but it must never skip a fetch.

## Worked example: the board

A board page listens on the topic of its project. Every card write path
dispatches `App\Module\Board\Event\CardChanged` after its commit.
`PublishCardChangedOnCardChanged` turns the event into a `board.card_changed`
update with `cardId`, `change` and `contentChanged`, and no card content.

The `board_live` controller receives the update. It fetches
`/projects/{projectId}/board/cards/{cardId}/placement` with the session of the
viewer, and renders the Turbo Stream it gets back. The server builds that
stream for the viewer, so each viewer sees only the cards they may see. A burst
of updates for one card costs one fetch, and a card in a drag waits until the
drag ends.

A new path that writes a card must dispatch `CardChanged`, or open boards miss
the change until they reload. A change to the columns dispatches
`BoardColumnsChanged` instead, and the board reloads its frame.

The card drawer calls `emit('board.card_changed', …)` after a save. The board
therefore places the card at once, also with no hub.

## In production

On the single-host stack the hub sits behind a compose profile and stays off
unless you ask for it. On App Platform, setting `mercure_jwt_secret` runs a hub
as a second service and routes `/.well-known/mercure` on the app's own domain to
it, deriving all three variables itself. See
[Single-host Docker Compose](../operating/install/docker-compose.md) and
[App Platform](../operating/install/digitalocean.md).
