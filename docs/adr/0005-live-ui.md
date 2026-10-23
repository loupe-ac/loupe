---
title: "0005: Update the UI live whenever the instance can"
description: "A page that shows state another actor can change updates in place when that state changes. A reload is the fallback, never the design."
---

## Status

Accepted on 2026-10-21.

## Context

Most of the state that Loupe shows does not change because of the person who looks at it. A bridge worker moves a card, the workflow engine opens a pause, a reviewer approves a document, a forge reports a check, or the same person acts in another tab. A page that shows this state goes out of date while it is open.

Loupe already has the parts for a live page:

- `LiveUpdatePublisher` (`src/Mercure/LiveUpdatePublisher.php`) sends a private update to a Mercure topic after the change commits. The update names what changed and the origin of the change.
- `assets/lib/mercure.js` and `assets/lib/live.js` hold the subscription, its reconnect and the renewal of its token.
- The board, the backlog, the card page, the inbox and the sidebar pills listen today, with `board_live_controller.js`, `backlog_live_controller.js`, `card_live_controller.js`, `inbox_live_controller.js` and `live_pill_controller.js`.

The hub is optional. An instance with no hub reads the `live_updates.enabled` flag as off (`src/Mercure/LiveUpdates.php`), because a self-hosted instance must work with no extra service.

Live updates exist on some pages and not on others. Each new page asks again whether it needs them, and the answer depends on who builds it.

## Decision

A page that shows state an actor other than the current request can change updates that state in place when it changes. This applies whenever the instance has live updates on. Without them, the page is correct on its next load.

The rule in detail:

- An actor is a worker, the workflow engine, a scheduled job, a forge webhook, another person, or another tab of the same person.
- A new page, or a new part of a page, that shows such state ships with its live update in the same piece of work. A page with no live update must say why in its tech design.
- The update is a signal, not the data. It names what changed. The page then fetches the server-rendered fragment, such as a Turbo stream, through a normal request. The normal request applies the access rules of the viewer, so the topic payload carries no content that a viewer may not see.
- Publish through `LiveUpdatePublisher`, after the change commits. A rolled-back change sends nothing.
- Each topic is private and goes through a `MercureTopicAuthorizerInterface`. The `project-authz` skill carries the rules.
- A page catches up after a reconnect. It fetches what it missed, as the board does.
- A live change must not disturb the work of the person on the page. When the person drags, types in a form or has a dialog open, the update waits until that ends. It does not drop.
- One exception: a live update that replaces no input of an open dialog does not wait. The warning in the Finish review dialog of a draft document updates this way, and it keeps the verdict and the note.
- A failed fetch retries with a growing wait. After the last retry, the page says that live updates stopped. It does not show old state as current.

"When the instance can" means a configured hub with the flag on. It does not mean "when it is cheap to build". The cost of a live update is a cost of the feature.

## Rejected options

- A reload only, with a refresh button: this is the cheapest to build. But the person sees old state with no sign that it is old, and Loupe shows work that agents do while nobody watches. The board would then lie about the state of the work.
- Polling on a timer: this works with no hub. But each open page costs requests while nothing changes, and the delay is the poll interval. An instance that wants live pages can run a hub, and `docker/compose/prod.yaml` already ships one.
- Send the rendered HTML in the Mercure payload: the page needs no second request. But one publish then serves every viewer of a topic, so it cannot apply the access rules of each viewer. It also renders the fragment once per change, whether anyone watches or not.
- Decide per page: each page gets the effort it seems to need. But that is the state today, and it gives pages that differ for no reason the person can see.

## Consequences

Better:

- A person can trust what an open page shows, or see that live updates stopped.
- Each new page follows one pattern: a signal, a fetch of a fragment, and a catch-up on reconnect.
- The access rules stay in one place, the normal request.

Worse:

- A feature costs more. It needs a topic, a publish after commit, a listener, a catch-up and tests for each.
- Each signal costs one fetch for each open page. A burst of changes must be merged into one fetch, as the board does with its settle delay.
- An instance with no hub gets pages that are correct only on load. The docs for operators must say what the hub adds.

Watch for a page that shows state from a worker, the workflow or another person, and has no listener. Each case breaks this rule. Add the live update, or record in the design why the page has none.
