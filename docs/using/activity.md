---
title: "Activity: Events"
description: "The Events tab of the Activity page, which lists the durable events of a project."
---

Open **Activity** in a project's sidebar, then the **Events** tab, to read its durable event history.
The **Runs** tab lists the [worker runs](worker-runs.md).
The [Analytics](analytics.md) page charts the metrics of the runs and compares the variants of each [experiment](experiments.md).

## What a row shows

| Column | Meaning |
|---|---|
| Event | the event label in bold, then its subject in grey. A card move names the card and its two columns, such as `#292 Tech design → Implementation` |
| Delivery | whether Loupe delivered the event to the bridges: **Delivered**, **Pending delivery** or **Delivery failed** |
| Family | the family of the event, such as **Board** or **Pull requests** |
| Sequence | the order number that Loupe gave the event when it recorded it. Loupe delivers events in this order |
| Recorded | how long ago Loupe recorded the event. Hover over it to see the exact time |

Delivery status describes delivery to bridges, not the outcome of an agent's work.
A **Delivery failed** chip shows a help icon.
Hover over or focus the chip to see the number of failed attempts and the last error.
A touch screen hides the help icon and this text.

Click a row to open its linked work, such as the card of a card event.
Card events link to the current card when it still exists in this project.
Deleted cards leave their event records without a link. Disabling the board also hides card links.
A row with no linked work does not open anything.

The list reads newest first, 20 events to a page.

## Search and filters

The search box matches the event type and the recorded event data.
So a card number, a column slug or a word of the type, such as `moved`, finds its rows.
Each word must match.

The family filter keeps one family of events. **Documents** also keeps the older document review events.

Every control lands in the URL, so a filtered view is a link you can share.
Select **Clear** to go back to the whole list.
The count beside the filters shows how many events match.

## Live updates

The page follows the events of the project while it stays open.
This needs a Mercure hub and the `live_updates.enabled` flag. Without them, the page shows a change on its next load.

- **First page.** A new event, or a changed delivery status, updates the list in place. Rows that did not change keep their place, and the search box keeps what you typed. A new row fades in.
- **Later pages.** The rows keep their order, so a new event never moves them. A row updates in place when its delivery status changes. A banner says how many newer events Loupe recorded, with a link to the first page.
- **After a reconnect.** A page that lost its connection to the hub catches up once it is back.

The **Undelivered events** page of a project (the project outbox) updates the same way. A row leaves the list when Loupe delivers its event.
