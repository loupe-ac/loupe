---
title: 'Rotating secrets'
description: 'Replacing the production deploy secrets with one command, the order of a run, how to recover from a failure, and the guard that stops agents from reading the secret files.'
---

Rotate the deploy secrets when one leaks, or on a schedule. A rotation has two phases.

1. `just rotate-secrets` collects. It makes a new value where it can, and asks you for each value that only a vendor can issue, all in one pass. It saves them as pending and changes nothing live.
2. A Claude session applies. The recipe offers to start it when the collection ends. Its `project-rotate-secrets` skill runs `bin/rotate-secrets apply`, runs the re-encrypt check on production, and gives you the old credentials to revoke by hand.

The recipe covers an App Platform deployment that you manage with the Terraform files in `terraform/`. It never prints a secret value. The live values stay in the secret store, or in `terraform/terraform.tfvars` when you have no store.

## The kinds of secret

Each secret belongs to one kind. The kind sets how the recipe gets the new value and what the rotation breaks.

| Kind                           | Secrets                                                                                                                                                                                                                                     | How the recipe gets the new value                                                  | Side effect                                                    |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Generated locally              | `app_secret`                                                                                                                                                                                                                                | Makes a random value                                                               | Remember-me cookies and signed links end                       |
| Generated locally              | `install_token`, `health_probe_token`, `mercure_jwt_secret`                                                                                                                                                                                 | Makes a random value                                                               | None                                                           |
| Generated locally              | `oauth_private_key`, `oauth_private_key_passphrase`, `oauth_encryption_key`, `oauth_public_key`                                                                                                                                             | Makes a new RSA key pair and a new key                                             | Every MCP client and bridge signs in again                     |
| Rotated through Terraform      | The export bucket key, `digitalocean_spaces_key.exports`                                                                                                                                                                                    | Replaces the key resource                                                          | None                                                           |
| Rotated through the GitHub API | `github_app_webhook_secret`                                                                                                                                                                                                                 | Makes a value, then sets it on the GitHub App                                      | Events in a short window fail, and the recipe sends them again |
| Vendor dashboard               | `do_token`, `spaces_access_id`, `spaces_secret_key`, `registry_credentials`, `mailer_dsn`, `stripe_secret_key`, `stripe_webhook_secret`, `oauth_google_secret`, `oauth_github_secret`, `github_app_client_secret`, `github_app_private_key` | Prints the vendor page and reads the new value with hidden input                   | You revoke the old value                                       |
| Vendor dashboard, when set     | `export_storage_key`, `export_storage_secret`, `site_review_widget_preview_token`                                                                                                                                                           | The same, only when the value is set                                               | You revoke the old value                                       |
| Own step                       | `app_encryption_key`, with `app_encryption_key_previous`                                                                                                                                                                                    | The three-deploy step in [Rotating the encryption key](rotating-encryption-key.md) | Old database dumps stay readable with the old key only         |
| Own step, by name only         | `vapid_private_key`, with `vapid_public_key`                                                                                                                                                                                                | Makes a new key pair                                                               | Every web push subscription stops                              |

The vendor pages are:

| Secret                                                                      | Where to make the new value                                               |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `do_token`, `spaces_access_id`, `spaces_secret_key`                         | The API and Spaces keys pages of the DigitalOcean account                 |
| `registry_credentials`                                                      | The token page of the GitHub account that owns the container registry     |
| `mailer_dsn`                                                                | The page of your mail provider. The recipe asks for the new password only |
| `stripe_secret_key`, `stripe_webhook_secret`                                | The API keys and webhook pages of the Stripe dashboard                    |
| `oauth_google_secret`                                                       | The credentials page of the Google Cloud project                          |
| `oauth_github_secret`, `github_app_client_secret`, `github_app_private_key` | The developer settings of GitHub                                          |
| `site_review_widget_preview_token`                                          | Issued by the `loupe` CLI for a preview instance                          |

Four rules apply to the kinds.

- The account Spaces key pair (`spaces_access_id`, `spaces_secret_key`) belongs to no Terraform resource. Only the vendor page can replace it.
- `export_storage_key` and `export_storage_secret` stay empty while `create_export_bucket` is true. They matter only for a bucket that you bring.
- A secret whose current value is empty or unset is skipped, and the recipe prints `<name>: not set, skipped`. An empty `install_token` therefore stays empty, and no feature turns on by accident.
- `sentry_dsn` and the client ids are not secret. The recipe leaves them alone.

## Run it

Rotate every secret except the web push key:

```bash
just rotate-secrets
```

Rotate only the secrets that you name:

```bash
just rotate-secrets app_secret mercure_jwt_secret
```

A name of one secret in a pair runs the step of its kind. For example, `oauth_private_key` rotates the whole OAuth key set.

For a vendor secret, the recipe prints the page to open, and the token scopes when a token needs them. It opens no browser. It reads the new value with hidden input, and checks its shape before it saves anything. For a multi-line value, such as the GitHub App private key, type a line that holds only a period to end the input, or type the path of a file.

In a terminal, the recipe asks for each vendor secret in turn, and then shows a numbered menu of them. The menu marks each secret `entered` or `skipped`, and never shows a value.

1. Press Enter at a prompt to skip that secret. A skipped secret is not rotated, and it is not on the revoke list.
2. Type a number in the menu to enter that secret again, for example after a wrong paste.
3. Type `s` to save every entered value as pending.
4. Type `q` to quit. Nothing is saved.

A value with the wrong shape is asked again. Without a terminal, the recipe reads the values in turn with no menu, and stops on an empty or wrong value. `ROTATE_MENU=1` shows the menu without a terminal.

When every value is collected, the recipe lists the secret names and asks `Start a Claude session that applies them now? [y/N]`. Answer `y` to start `claude` with the prompt that names the skill. Any other answer leaves the values pending. Start the session later with the command that the recipe prints, or drop the values with `bin/rotate-secrets discard`.

A second collection is refused while values are pending, and while a webhook switch is pending.

### Impact groups

An impact group names the secrets whose rotation has the same effect on the people who use the app. Give a group in place of names, or next to them:

```bash
just rotate-secrets @no-relogin
just rotate-secrets @relogin-users mercure_jwt_secret
```

| Impact group       | Secrets                                                                                                                                                                              | Effect                                                    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------- |
| `@no-relogin`      | The encryption key step, `install_token`, `health_probe_token`, `mercure_jwt_secret`, the export bucket key, `github_app_webhook_secret`, and every vendor secret of the kinds table | Nobody signs in again                                     |
| `@relogin-users`   | `app_secret`                                                                                                                                                                         | People sign in again after you empty the `sessions` table |
| `@relogin-clients` | The OAuth key set and `oauth_encryption_key`                                                                                                                                         | Every MCP client and bridge signs in again                |

The three groups together hold every secret of a full run. The web push key is in no group.

A run by group keeps the order of a full run, so the encryption key step still runs first. After the collection, a run by group or by names prints the groups that it did not rotate in full, for example `Not rotated in full: @relogin-users @relogin-clients`. A full run prints no such line. An unknown group stops the run before it collects anything, and the error lists the groups.

Sessions live in the database, and a new `APP_SECRET` does not end them. `APP_SECRET` signs only the remember-me cookies and the signed links. So when a run rotates `app_secret`, the apply prints the command that ends every session. The session relays it to you and does not run it, because it signs you out too. Run it on production:

```bash
bin/console dbal:run-sql 'DELETE FROM sessions'
```

## Where the pending values wait

With a secret store, the pending values wait in `terraform/secrets.pending.enc.yaml`. It is a second sops file, encrypted to the age recipients of the live store. With no store, they wait in `terraform/.rotate-secrets.tmp.pending.json`, a plain file with mode 600, which the read guard refuses. The file `terraform/.rotate-secrets.pending` lists the pending names and the progress of the apply. It holds no secret.

## The order of one apply

`bin/rotate-secrets apply` runs these steps. Each step that applies saves a plan, checks it, and applies that saved plan, so Terraform asks no question.

1. The apply finishes a pending webhook switch, when the file `terraform/.rotate-secrets.webhook-since` exists.
2. The encryption key step runs, when the collection holds it. It applies twice, then stops with exit code 10. Run the re-encrypt check, then `bin/rotate-secrets apply --reencrypted` makes the third apply.
3. The apply moves every other pending value to the live store, or to `terraform/terraform.tfvars` for a value that is not in `terraform/secrets.list`. It reads each value back before it replaces the file.
4. The apply writes the webhook switch file, with the phase `applying`, the start time and the ID of the active app deployment. This step applies only when the collection holds the webhook secret.
5. The apply applies once. It replaces the export bucket key in the same apply.
6. The apply waits until a deployment that is newer than the recorded one is active. It polls every 15 seconds and stops after 30 minutes.
7. The apply sets the phase to `applied`, and sets the new webhook secret on the GitHub App.
8. The apply lists the events since the recorded start time and sends again each event that has no good delivery. It waits until each of those events has a delivery with a status code from 200 to 399. It checks every 10 seconds and stops after 2 minutes.
9. The apply removes the pending files and prints the revoke list.

### The plan check

The check reads the saved plan as JSON. A plan may update the app, `module.app.digitalocean_app.app`, but only in the environment variables of the rotated secrets, such as `INSTALL_TOKEN` for `install_token`. A rotation of `registry_credentials` may also change the registry credentials of the app images. A plan may replace `digitalocean_spaces_key.exports[0]` when the collection holds the export bucket key, together with `EXPORT_STORAGE_KEY` and `EXPORT_STORAGE_SECRET`.

Any other change stops the apply with exit code 11. The script lists each other resource with its actions, and each other app field by name, such as `spec.env.ADMIN_EMAIL` or `spec.service.instance_size_slug`. It prints no value. Nothing is applied. To accept a change, run `bin/rotate-secrets apply --allow <name>`, once for each name. `--allow module.app.digitalocean_app.app` accepts every app field. The usual cause is a Terraform change that nobody has applied yet.

## The webhook switch

GitHub signs each event with the webhook secret. Loupe accepts one secret only. The recipe gives Loupe the new secret first, and tells GitHub only after the new deployment is active. Events that arrive in between fail the signature check, and GitHub does not send them again by itself. The recipe sends them again after the switch.

The file `terraform/.rotate-secrets.webhook-since` holds no secret, and git ignores it. It records how far the run got.

GitHub sends again only the deliveries of the past 3 days. Run the retry within 3 days of the first apply. When the recorded start time is older, the retry prints a warning, and the older failed events are lost.

## If a step fails

Each failure leaves the pending files in place. Fix the cause, then run `bin/rotate-secrets apply` again. It resumes where it stopped and makes no new value.

- A write fails: the old `terraform/terraform.tfvars` stays whole, and no temporary file stays behind.
- An apply fails: the live store already holds the new values. The rerun applies them again.
- The deployment fails: the pending switch file stays. Fix the cause of the failed deployment first, because the rerun stops on it again.
- The webhook switch fails, or an event is not delivered after its resend: the pending switch file stays. Rerun within 3 days. The rerun applies again only when the first apply did not finish. It sends again each event since the first apply that has no good delivery.
- The encryption key step stops: follow [Rotating the encryption key](rotating-encryption-key.md). The progress file `terraform/terraform.tfvars.rotating` keeps the deploy that the step reached.

To give up before the apply starts, run `bin/rotate-secrets discard`. It drops the pending values, and production and the live store stay as they are. After the first `bin/rotate-secrets apply`, discard refuses. The live store can then hold new values that production lacks, and the pending files are the only record of the run. Finish the rotation with `bin/rotate-secrets apply`.

## Checks after a run

1. Run `just healthz`. It prints the live version.
2. Sign in to the app.
3. Trigger a GitHub event, such as a comment on a pull request, and check that it reaches its card.
4. Check that `terraform/.rotate-secrets.webhook-since` and `terraform/.rotate-secrets.pending` no longer exist.
5. Work through the revoke list. Each line names an old credential that you must revoke at the vendor.

## The web push key

A full run skips `vapid_private_key`, because a new pair stops every web push subscription. The recipe prints `vapid_private_key: skipped, rotate it by name; every push subscription stops`. Name the secret to rotate it. After a full leak, run the full rotation and then name the key in a second run.

## The agent read guard

An agent can print a secret file by mistake, for example with `cat` or `grep`. The guard refuses that command before it runs. It covers Claude Code sessions.

The guard protects these files:

- `terraform/terraform.tfvars`
- `terraform/terraform.tfstate` and its backup copies
- a saved plan, `terraform/*.tfplan`
- the age key file, `sops/age/keys.txt`
- `docker/compose/prod.env`
- the temporary files `terraform/.rotate-secrets.tmp.*`

It has two layers.

- Deny rules in `.claude/settings.json` refuse `Read` on those files. They hold in every permission mode, and they also cover `@` mentions and the selection of an editor.
- The hook `.agents/hooks/no-secret-reads.sh` checks every word of a shell command, and the path of `Read`, `Grep`, `Edit` and `Write`. It matches on the file name, with any folder before it, so a worktree cannot reach the main checkout files through `../..`.

The hook also refuses a command that prints the Terraform state without naming it, such as `terraform state pull`. It refuses the commands that read the secret store: `bin/tf-secrets exec`, which can run any command with every secret in its environment, and `sops decrypt`, `sops edit`, `sops exec-env` and `age --decrypt`. It refuses a recursive search over a folder that holds a secret file. For that search, use `rg` or `git grep`, which skip files that git ignores.

Each refusal names a safe path:

- `just tfvars-names` prints the names assigned in `terraform.tfvars` and `prod.env`, and no value.
- `just healthz` prints the live version of the app. It reads the probe token and never prints it.
- `just rotate-secrets` collects new secrets without showing them, and `bin/rotate-secrets apply` applies them.
- `just tf-plan` and `just tf-apply` run Terraform with the secrets from the store. `bin/tf-secrets names` lists the names the store holds.

### What the guard does not stop

The guard stops a mistake. It does not stop a session that tries to get around it.

- The hook matches command text. A script that opens the file itself passes, and so does a command that builds the path at run time.
- A session can turn every hook off with the `disableAllHooks` setting. The deny rules still hold then, but they do not cover `grep`, `awk` or `less`.
- The JSON plan of `terraform plan` names no file, so the guard does not stop an agent from printing it.
- The guard covers Claude Code only. Other agent tools have no hook in this repository.
- A temporary file with a protected name, such as `/tmp/terraform.tfvars`, is refused too.
