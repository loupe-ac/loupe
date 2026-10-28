---
title: "Set up web push"
description: "Make the push key pair, set it, and turn on web push for your instance."
---

Web push tells a project owner about a blocking inbox ask when no Loupe tab is
open. Push is off on a new or upgraded instance. It needs a key pair in the
environment and a feature flag. Users then turn push on per device. See
[Push notifications](../using/notifications.md) for what they see.

Loupe talks to the push services of the browsers directly. It needs no account
at a third party.

## Before you start

The worker sends each push over HTTPS. Production needs outbound access from
the worker to the push services of the browsers your users run:

- Apple, on hosts that end in `.push.apple.com`
- Google, on `fcm.googleapis.com`
- Mozilla, on hosts that end in `.push.services.mozilla.com`
- Microsoft, on hosts that end in `.notify.windows.com`

Loupe refuses a device whose push address is on another host. The browser then
cannot turn push on.

## Make the key pair

Run this once:

```bash
bin/console app:notifications:generate-keys
```

The command prints a `VAPID_PUBLIC_KEY` line and a `VAPID_PRIVATE_KEY` line. It
stores nothing, so copy both lines. The private key is a secret. Keep it out of
logs and out of version control.

**Never change the pair after users subscribe.** A new pair makes every existing
push subscription stop working. Each user must then turn push on again on each
device.

## Set the keys

Set both variables in the environment of the web container and the worker.
[Environment variables](../reference/environment.md) describes them.

- With Terraform, set `vapid_public_key` in `terraform.tfvars`. Put
  `vapid_private_key` in the secret store with
  `printf '%s' "$KEY" | bin/tf-secrets set TF_VAR_vapid_private_key`, or in `terraform.tfvars` when
  you use no store. Then run `just tf-apply`.
- With the compose stack, set `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` in
  `prod.env`, then restart the stack.

An empty, malformed or mismatched key keeps push off. The deploy works before
you set them.

## Turn on the flag

Open **`/admin/feature-flags`** and switch on `notifications.web_push.enabled`.
The change needs no restart. The flag ships off on every instance. A migration
adds its row when you upgrade.

Push is available only when the flag is on and the key pair is valid. Until
then the Notifications page of each user shows "Push is not set up on this
server", and an administrator sees a link to this page instead.

## Check it

1. Open **`/admin/status`**. The **Web push** row must show OK. A failure names
   the bad key: missing, not a valid key, or not the pair of the other key. The
   row never shows a key value.
2. Open `/manifest.webmanifest` and `/sw.js`. Both must return 200.
3. On a laptop, open **Notifications** in your account settings, turn on push,
   and select **Send test**. The test push must arrive.
4. On an iPhone, add Loupe to the Home Screen, sign in there, turn on push, and
   tap **Send test**.

## What the install changes

- Two new tables, `push_devices` and `notification_preferences`. They are new,
  so the migration takes no long lock and needs no backfill.
- The two messages that send a push use the existing `jobs` transport. The
  worker command does not change.
- The service worker and the manifest come from the same origin. The content
  security policy needs no new origin.
- The web server sends `/sw.js` with `Cache-Control: no-cache`, so a new worker
  reaches browsers at once. It sends `/manifest.webmanifest` with the type
  `application/manifest+json`.

## Problems

**A user cannot turn push on**

Check **`/admin/status`** first. If the row is OK, the browser may use a push
service on a host that Loupe does not accept.

**A device shows "Stopped working"**

The push service refused the device for good. The user opens Loupe on that
device and turns push on again. See
[When a device stops working](../using/notifications.md#when-a-device-stops-working).

**All devices stopped at once**

Someone changed the key pair. Each user must turn push on again.

**A push is slow or missing**

A failed push retries three times on the `jobs` transport. After that the
message goes to the `failed` transport. See
[Failed messages](failed-messages.md).
