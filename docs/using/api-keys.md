---
title: "API keys"
description: "Create, use and revoke an API key for scripts and CI that call the public Loupe API."
---

An API key is a long-lived credential for the public API at `/api/v1`. Use it
where nobody can open a browser, such as a script or a CI job. A key signs the
caller in as you.

A key works on the public API only. It does not work on `/mcp`, and it does not
work on the other `/api` endpoints that the CLI and the widget use. MCP needs an
OAuth sign-in. See [Connected apps](connected-apps.md).

## A key reaches the whole account

A key has all your rights. It reaches every project you own, and it can do
everything you can do through the public API. A key has no scope that narrows
it, and you cannot limit it to one project.

Keep a key secret. Store it in the secret store of your CI system. Do not put
it in a repository, a log or a chat message.

## Create a key

1. Open **Your account** and select **API keys**. The page is at
   `/account/api-keys`.
2. Select **Create key**.
3. Enter a name that tells you where the key is used, such as `CI deploy`. The
   name has up to 100 characters.
4. Choose when the key ends: in 30 days, in 90 days, in 1 year, or never.
5. Select **Create key** in the dialog.

Loupe then shows the whole key once. Select the copy button and store the key.
When you close the dialog or reload the page, the page shows only the start of
the key, such as `loupe_key_ab12…`. Loupe keeps a hash of the key and the start,
so it cannot show the key again. Create a new key if you lose one.

A key starts with `loupe_key_`.

Loupe sends you one email when you create a key. The email names the key and
shows when it ends. It contains no key. When Loupe cannot queue the email, the
key still works and the page still shows it. Loupe then tries to send the email
again each hour until the queue accepts it.

## Use a key

Send the key in the `Authorization` header of each request:

```sh
curl -H "Authorization: Bearer loupe_key_<your key>" https://<host>/api/v1/<endpoint>
```

A request with a wrong, revoked or unknown key gets `401`. A key past its end
date gets `401` with the code `api_key_expired`. A key stops working at once
when you suspend or delete the account that owns it.

The page shows when each key was last used. Loupe writes this time at most once
every five minutes for a key.

## How Loupe records what a key does

A key is a separate kind of actor. Each change that a key makes names the key.

1. A card history row reads "API key 'CI' (your name)". The row keeps the key name after you remove the key.
2. A verdict that a key records reads "Recorded through API key 'CI'".
3. A comment that a key writes has you as author, and reads "API key 'CI' (your name)".

An action through a CLI sign-in is not a key action. Loupe records it as an agent.

## Expiry

Choose an end date for every key, unless a key must live without one. A key with
an end date limits the damage of a leak.

Loupe emails you 7 days before a key ends. The email names the key and the end
date. Loupe sends it once for each key. A key that never ends gets no email.

A key stops working at its end date. The row stays on the page and shows how
long ago the key ended. Select **Remove** on that row to delete it. You cannot
extend a key. Create a new one and replace the old one in your scripts.

## Revoke a key

Select **Revoke** on the row of the key. The key stops working at once, and the
row leaves the page. Revoke a key when you no longer use it.

Revoke a key at once when you think it leaked:

1. Revoke the key on the API keys page.
2. Create a new key and put it in your scripts.
3. Check where the old key was stored, and remove it there.

An administrator can also revoke any key. See [The admin area](admin.md).

## Data export and deletion

The [data export](data-exports.md) lists each key with its name, the start of
the key and its dates. It never holds a key value. Deleting your account
deletes every key.
