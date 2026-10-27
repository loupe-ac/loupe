---
title: "Rotating the encryption key"
description: "Replacing APP_ENCRYPTION_KEY without losing the GitHub tokens and webhook secrets that it encrypts."
---

Rotate `APP_ENCRYPTION_KEY` when it leaks, or on a schedule. The app encrypts new values with the current key. It reads old values with the current key or with `APP_ENCRYPTION_KEY_PREVIOUS`. A console command moves every stored value to the current key. Then you remove the old key.

The key encrypts three values: the secret of a project's GitHub webhook, and the access token and refresh token of a user's GitHub connection.

## Before you start

- Rotation does not protect old backups. A dump taken before the rotation stays readable with the old key. Delete the dumps you no longer need, or keep them with the old key, as [Restoring the database](restoring.md) says.
- The old key still opens production data until the last deploy of this page. Keep the window short.
- If the key leaked together with a copy of the database, the GitHub tokens in that copy are exposed too. Rotation does not void them. Ask users to connect GitHub again, or revoke the tokens on GitHub.

## The steps

A rotation takes three deploys. The first deploy teaches every container the new key before any container writes with it. Old and new containers can run side by side for a short time during a deploy, and this order leaves no window in which an old container fails to read a fresh value.

1. Make a new key.

   ```bash
   php -r 'echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;'
   ```

2. **Deploy 1.** Set `APP_ENCRYPTION_KEY_PREVIOUS` to the new key. Leave `APP_ENCRYPTION_KEY` as it is. Deploy.
3. **Deploy 2.** Set `APP_ENCRYPTION_KEY` to the new key. Set `APP_ENCRYPTION_KEY_PREVIOUS` to the old key. Deploy.
4. Run the re-encrypt command in the web container.

   ```bash
   bin/console doctrine-extra:reencrypt
   ```

   It prints, for each encrypted column, the rows it re-encrypted, the rows it skipped and the rows it could not read. It never prints a value. The command works in batches of 100 (`--batch-size`). It is safe to run again after a failure, and safe to run while the app serves traffic.
5. Check that every `unreadable` count is 0. A value that no key opens stays as it is, and the command exits with a failure code. Find out why before you go on. The usual cause is a wrong previous key.
6. **Deploy 3.** Clear `APP_ENCRYPTION_KEY_PREVIOUS`. Deploy. The old key is now gone from the environment.
7. Delete every copy of the old key.

## Run it with the recipe

On App Platform, `just rotate-secrets app_encryption_key` runs steps 1 to 6 for you. It edits `terraform/terraform.tfvars`, applies it three times, and stops at step 4 until you confirm. It never prints a key. At the end it lists the old credentials to delete by hand.

The script asks you to run the command in step 4 yourself, because it cannot reach a console in an App Platform container.

## On Docker Compose

Set the two variables in `docker/compose/prod.env`, and recreate the `web` and `worker` containers for each deploy. Run step 4 with `docker compose exec web bin/console doctrine-extra:reencrypt`.

## If a step fails

- The command reports an unreadable value: the value was made with a key that is in neither slot. Put the missing key back in `APP_ENCRYPTION_KEY_PREVIOUS`, deploy, and run the command again.
- The command stops halfway: run it again. It skips the rows that already use the current key.
- You removed the old key too early: put it back in `APP_ENCRYPTION_KEY_PREVIOUS` and deploy. Nothing was deleted.
