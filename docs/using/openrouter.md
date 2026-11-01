---
title: "OpenRouter key"
description: "Save an OpenRouter management key on your account so that Loupe can read the billed cost of Codex runs on OpenRouter."
---

Loupe reads the billed cost of a Codex run on OpenRouter with a management key.
A management key can read the usage of your OpenRouter account. A normal API key cannot.
Loupe uses the key only to read costs.

You can save the key in two places. The account key covers every project you own.
A project key covers one project and overrides the account key there.

## Save an account key

1. Create a management key on the [OpenRouter management keys page](https://openrouter.ai/settings/management-keys).
2. Open **Your account** and select **OpenRouter**.
3. Paste the key in the **Management key** field and select **Save the key**.

The page then shows the state of the key:

- A **Connected** chip, the end of the key, and the time of the last cost read.
  The end of the key looks like `sk-or-v1-…a3f9`. Loupe never shows the whole key again.
- The line **No cost read yet** until Loupe reads a cost with the key.
- A **Refused by OpenRouter** chip when OpenRouter no longer accepts the key.
  Point at the chip to see since when. Runs since then show no cost.
  Select **Replace the key** and save a new key to fix it.

## The test on save

Loupe tests the key with OpenRouter before it saves the key. It does not save a key that fails the test.
The page tells you why:

- **OpenRouter says this is not a management key.** You pasted a normal API key.
  Create a management key on the page that the message links to.
- **OpenRouter refused the key.** The key is wrong or revoked.
- **Loupe could not reach OpenRouter. Try again.** OpenRouter did not answer in time.
  The old key, if you had one, stays in place.

## The inbox notice

When OpenRouter refuses a key on a cost read, Loupe opens a notice in the
[inbox](inbox.md) of the project whose run it read. The notice is called
**OpenRouter refused the key**. It says whether OpenRouter refused the account
key or the key of the project, and where to replace it.

An account key covers every project you own. When OpenRouter refuses it, each
project whose cost read fails gets its own notice.

Loupe closes the notices of a key in two cases:

- OpenRouter accepts the key again on a later read. The notice closes as **resolved**.
- You replace or remove the key. The notice closes as **cancelled**.

With the inbox off, Loupe opens no notice. The **Refused by OpenRouter** chip
on this page still shows the state of the key.

## Replace or remove the key

Select **Replace the key** to save another key. The new key replaces the old one after it passes the test.
The form is always empty, because Loupe does not show a saved key.

Select **Remove** to delete the key. Runs on OpenRouter then show no cost,
unless a project has its own key.

## A key for one project

The project owner can save a key for one project. Open the project settings and select **OpenRouter**.
Only the project owner sees this tab.

The tab shows the **Key in use**. It is one of three states:

- **This project's key.** You can replace it or remove it. When you remove it,
  the project uses the key of your account again.
- **Your account key.** Select **Use a key for this project** to save a key that applies to this project only.
- **No key.** The tab offers a key for the project or a key for your account.

The project key wins over the account key. Loupe looks for the key of the project first,
and then for the account key of the project owner.

## Your data

Loupe stores the key encrypted. The operator's `APP_ENCRYPTION_KEY` protects it.
See [Rotating the encryption key](../operating/rotating-encryption-key.md).

When you delete your account, Loupe deletes your keys. A project key goes away with its project.
OpenRouter keeps the key until you revoke it on OpenRouter.
The [data export](data-exports.md) includes `openrouter_keys.json`.
It says whether a key exists and gives its dates. It never contains the key.
