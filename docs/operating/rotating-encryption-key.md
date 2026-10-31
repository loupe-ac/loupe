---
title: "Rotating the encryption key"
description: "Replacing APP_ENCRYPTION_KEY without losing the GitHub tokens, webhook secrets and OpenRouter keys that it encrypts."
---

Rotate `APP_ENCRYPTION_KEY` when it leaks, or on a schedule. The app encrypts new values with the current key. It reads old values with the current key or with `APP_ENCRYPTION_KEY_PREVIOUS`. A console command moves every stored value to the current key. Then you remove the old key.

The key encrypts four values: the secret of a project's GitHub webhook, the access token and refresh token of a user's GitHub connection, and the OpenRouter management key of a user or a project.

## Before you start

- Rotation does not protect old backups. A dump taken before the rotation stays readable with the old key. Delete the dumps you no longer need, or keep them with the old key, as [Restoring the database](restoring.md) says.
- The old key still opens production data until the last deploy of this page. Keep the window short.
- If the key leaked together with a copy of the database, the GitHub tokens and the OpenRouter keys in that copy are exposed too. Rotation does not void them. Ask users to connect GitHub again, or revoke the tokens on GitHub. Ask users to replace their OpenRouter keys, and revoke the old ones on OpenRouter.

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

On App Platform, `just rotate-secrets app_encryption_key` makes the new key and saves it as pending. The apply session then runs steps 1 to 6 with `bin/rotate-secrets apply`. It never prints a key. At the end it lists the old credentials to delete by hand.

The apply stops after the second deploy with exit code 10, because the script cannot reach a console in an App Platform container. The session runs step 4 in the production console, and reads every `unreadable` count. When each count is 0, it runs `bin/rotate-secrets apply --reencrypted`, which makes the third deploy. A count above 0 stops the session, and the old key stays in place.

When the apply stops, because an apply fails or a count is not 0, run `bin/rotate-secrets apply` again. It saves its progress in `terraform/terraform.tfvars.rotating`, and resumes with the same two keys. The collection refuses to start while `app_encryption_key_previous` is set and no progress is saved.

## On Docker Compose

Set the two variables in `docker/compose/prod.env`, and recreate the `web` and `worker` containers for each deploy. Run step 4 with `docker compose exec web bin/console doctrine-extra:reencrypt`.

## If a step fails

- The command reports an unreadable value: the value was made with a key that is in neither slot. Put the missing key back in `APP_ENCRYPTION_KEY_PREVIOUS`, deploy, and run the command again.
- The command stops halfway: run it again. It skips the rows that already use the current key.
- You removed the old key too early: put it back in `APP_ENCRYPTION_KEY_PREVIOUS` and deploy. Nothing was deleted.
