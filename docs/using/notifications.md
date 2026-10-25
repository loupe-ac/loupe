---
title: "Push notifications"
description: "Get a push on your phone or laptop when an agent waits for your answer in the inbox."
---

An agent that waits for your answer is stuck until you open Loupe. A web push
tells you at once, even when no Loupe tab is open. The push shows on your
laptop, your phone or both.

Push needs the operator of your instance to set it up first. If the settings
page says "Push is not set up on this server", ask your administrator. The
operator steps are in [Set up web push](../operating/web-push.md).

## What sends a push

A push goes out when a new blocking ask opens in the [inbox](inbox.md). A
blocking ask comes from an agent, from a workflow question, or from a card that
waits for you.

- The push title is the project name.
- The push text is the title of the first question. An ask with more questions
  adds "+2 more questions", with the right number.
- A tap on the push opens the project inbox with that ask selected.
- An ask sends one push when it opens. Questions that an agent adds to the same
  open ask send no second push.

Loupe holds the push back while you use Loupe. If any of your devices has a
Loupe window in front, and you used it in the last minute or so, no device gets
the push. A window that sits idle does not hold it back. When Loupe cannot tell
whether you look, it sends the push.

A push for an ask that you already answered can stay on a device. Loupe clears
it when you open Loupe on that device, or when that device gets its next push.

## Turn on push

Open **Notifications** in your account settings. The section comes after
**Profile**.

1. Open the page in the browser or app that must get the push.
2. Select **Turn on push**.
3. Choose **Allow** when the browser asks if Loupe may send notifications.

The device now shows in the **Web push devices** list, named by browser and
platform, with "this device" on the current one. Turning on push sends no
confirmation push. Select **Send test** on the device row to check it.

Each browser and each Home Screen app is a device of its own. Turn push on in
each one.

## Choose what you receive

The **What you receive** grid has one row for each event and one column for
each channel. The only column today is **Web push**.

| Event | Default | What it covers |
|---|---|---|
| Blocking inbox item | On | A new blocking ask. |
| Other inbox item | Off | A notice that is not blocking, such as a bridge outage. |

Each switch saves when you flip it. The choices apply to every project.

## Manage your devices

Each row of **Web push devices** has two buttons:

- **Send test** sends one test push to that device.
- **Remove** stops pushes to that device. Turn push on again there to get them
  back.

Signing out of Loupe on a device removes that device. You get no more pushes
there until you sign in and turn push on again.

## Push on iPhone and iPad

iPhone and iPad give web push only to a site on the Home Screen. A Safari tab
cannot get a push. You need iOS or iPadOS 16.4 or later.

1. Open Loupe in Safari.
2. Tap **Share** at the bottom of Safari.
3. Tap **Add to Home Screen**, then **Add**.
4. Open Loupe from your Home Screen.
5. Sign in. The Home Screen app keeps its own sign-in.
6. Open **Notifications** in your account settings, and tap **Turn on push**.

In a Safari tab, the Notifications page shows these steps in place of the
button. No other page shows them.

## When the browser blocks notifications

When the browser blocks notifications for Loupe, the device row says **Blocked
by this browser**. Only the browser can undo the block. The row lists the steps
for your platform. After you change the setting, reload the page and select
**I changed it, try again**.

On iPhone and iPad, open the Settings app, tap **Notifications**, tap **Loupe**,
and turn on **Allow Notifications**.

## When a device stops working

A push service can refuse a device for good. This happens when you remove the
Home Screen app or clear the browser data. Loupe keeps the device in the list
and marks it **Stopped working**, with the date.

The inbox pages also show a warning above their content. It names the device and
links to Notifications settings. To fix it, open Loupe on that device and turn
push on again, or remove the device. **Dismiss** hides the warning for that
device until it stops working again. The device row keeps its **Stopped
working** label.

## What the push service sees

Loupe encrypts every push for the browser that receives it. The push service of
Apple, Google, Mozilla or Microsoft sees the size, the time and the device
address. It cannot read the question.

The push on your lock screen shows the project name and the question title. To
hide them, use the preview setting of your phone.
