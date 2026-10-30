---
name: project-rotate-secrets
description: "Use when a session starts to apply a pending secret rotation, when `just rotate-secrets` collected new values, or when `bin/rotate-secrets apply` stopped with exit 10 or 11."
---

# Applying a pending secret rotation

`just rotate-secrets` collects the new values and saves them as pending. It changes nothing live. This skill applies them. You never see a secret value, and you must never try to read one. The secret read hook refuses the store, `terraform.tfvars`, saved plans and the `.rotate-secrets.tmp.*` files.

Invoke `project-deploy` as well. It carries the production console command and the checks after a deploy.

## The loop

Run `bin/rotate-secrets apply` from the main checkout. Read its exit code, and act on it:

| Exit | Meaning | Do this |
|---|---|---|
| 0 | Every pending value is live. | Go to "When it finishes". |
| 10 | The second encryption key deploy is live. | Go to "Re-encrypt". |
| 11 | A plan changes a resource beyond the rotated secrets. Nothing was applied. | Go to "An unexpected plan". |
| Other | A plan, an apply, a deployment or a GitHub call failed. | Report the error lines to the owner, and stop. A rerun of `bin/rotate-secrets apply` resumes. |

Each apply saves a plan, checks it, and applies that plan. Terraform asks nothing. The owner trusts the script's plan check, so do not ask the owner before an apply.

A rotation of `github_app_webhook_secret` waits for the new deployment and resends the failed GitHub deliveries. That can take several minutes. Run the command with a timeout of 10 minutes.

## Re-encrypt

Run the re-encrypt in the production `web` console. `doctl apps console` needs a TTY, so give it one with `script`:

```bash
APP=$(just tf-output -raw app_id | tail -1)
(sleep 8; printf 'bin/console doctrine-extra:reencrypt --no-ansi; echo RE_EXIT=$?; exit\n'; sleep 150) \
  | script -q /dev/null doctl apps console "$APP" web 2>&1 | tail -40
```

Read the table. Every `Unreadable` count must be 0, and `RE_EXIT` must be 0. Then run `bin/rotate-secrets apply --reencrypted`, and go back to the loop.

When a count is not 0, or the console does not connect, stop. Show the owner the table or the error line. The old key stays in `app_encryption_key_previous`, so nothing is lost. `docs/operating/rotating-encryption-key.md` says what to do next.

## An unexpected plan

The script prints each extra resource as `<address> <actions>`, with no value. Show the owner the list, and ask whether to accept it. Name the likely cause when you know it, such as a Terraform change on `main` that was never applied.

When the owner accepts, run `bin/rotate-secrets apply --allow <address>` with one `--allow` for each address. An `--allow` counts for one run only. Repeat it on a later run, such as `apply --reencrypted`, when the same address comes back. Otherwise stop. The pending values stay, and `bin/rotate-secrets discard` drops them.

## When it finishes

1. Run `just healthz`, and report the version it prints.
2. Relay the "Revoke by hand" list to the owner, item by item. The owner revokes each old credential at its vendor.
3. When the output asks to end the sessions after an `app_secret` rotation, relay that command to the owner. Do not run it yourself, because it signs the owner out too.
4. Report what changed: the secrets that rotated, the number of applies, and anything the owner still has to do.
