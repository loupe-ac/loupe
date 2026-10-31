---
name: project-deploy
description: "Use when deploying to production, running terraform apply, verifying the live version, diagnosing a no-op deploy, or changing the prod containers, worker command or `docker/compose/prod.yaml`."
---

# Deploying

Production is a DigitalOcean App Platform app that pulls `ghcr.io/loupe-ac/loupe:prod`. The infrastructure lives in `terraform/`. The secrets live in an encrypted sops store, `terraform/secrets.enc.yaml`, which `bin/tf-secrets` reads. The other real values sit in `terraform/terraform.tfvars`, which is gitignored.

## The two halves, and why they are not the same event

A deploy is two independent things. This skill exists to stop you from confusing them.

1. The image. `just build-prod` builds it, and `just push-prod` pushes it to the `prod` tag. This step changes nothing in production.
2. The app spec. `terraform apply` changes environment variables, instance sizes and routes. It creates a deployment.

A `terraform apply` deployment does not re-pull the image. The tag is fixed at `prod`, so App Platform sees the tag it already has and reuses the layers it holds. The spec change goes live and the code does not.

The platform works as designed. `variable "image_tag"` says so: "A fixed tag means App Platform's deployment history is the only record of what ran."

## What deploy notes hold

A card can link a Loupe document titled `Deploy notes: <card title>`, tagged `deploy-notes`. The tech design and implementation stages of the Loupe plugin write it. They read this section through `.loupe/lifecycle.md`, "Instruction files", item 8.

Record each item of the change that the deploy must handle:

- An environment variable, with its value or its source. `DeploymentConfigParityCheck` cross-checks `.env`, Terraform, the prod compose files and `docs/reference/environment.md`. Set a `terraform.tfvars` value before the deploy.
- A migration and its risk. The pre-deploy job runs it. Name long locks, backfills and irreversible steps.
- A new feature flag. `/install` seeds flags once, so ship a per-flag migration, as `migrations/Version20260906142530.php` does. Otherwise, name the manual switch-on.
- A new messenger transport or schedule. It must reach `worker_command` in `terraform/main.tf` and the `worker` service in `docker/compose/prod.yaml`.
- A new CSP origin in `config/packages/nelmio_security.yaml`.
- A new external service and its `DiagnosticInterface` check.
- A change to the bridge rule file format, with the edit it needs. Write the edit before `loupe update`, and never a `loupe bridge stop`.
- The order of the steps, for example `terraform apply` before or after the image push.
- The checks to do after the deploy.

## The production container layout

Production runs one container per process type, web and messenger worker, from the same image. `docker/prod/supervisord.conf` is only the image-default CMD of the web container. Never add a background process there as a `[program:]` block.

The worker's command belongs to the tool that orchestrates the containers. On App Platform that is `worker_command` in `terraform/main.tf`. On a single host it is the `worker` service in `docker/compose/prod.yaml`. Both run `supervisord -c /etc/worker-supervisord.conf`, which is the one exception to the rule above.

The worker container runs two consumers, `messenger:consume scheduler_default card_eval jobs` and `messenger:consume card_sweep`, each with `--time-limit=3600 --memory-limit=128M`. The second consumer keeps the low-priority workflow sweep from starving behind event-driven jobs. The schedule transport comes first in the first consumer, because a deep jobs backlog must not delay ticks.

`docker/compose/prod.yaml` is the reference single-host topology: web, worker, Postgres and a Mercure hub. It is a distributed artefact, so it must keep working alongside `terraform/`. The gitignored `docker/compose/prod.env` drives it, and `docker/compose/prod.env.example` is its template. Run it with `--env-file`, because Compose otherwise interpolates the development `.env`. `compose.yaml` stays dev-only and shares nothing with it.

## The secret read guard

A `PreToolUse` hook, `.agents/hooks/no-secret-reads.sh`, refuses an agent tool call that reads one of these files:

- `terraform.tfvars`
- a Terraform state file
- a saved plan (`*.tfplan`)
- `docker/compose/prod.env`
- the age key file
- a `.rotate-secrets.tmp.*` file

It also refuses a recursive search over a folder that holds one. It refuses `bin/tf-secrets exec`, which can run any command with every secret in its environment. It refuses the store reads `sops decrypt`, `sops edit`, `sops exec-env` and `age --decrypt`. Deny rules in `.claude/settings.json` cover the file tools.

These paths are safe:

- `just tfvars-names` lists the variable names that the two files set, with no value.
- `just healthz` reads the live version from `/healthz`.
- `just rotate-secrets` collects new secrets, and `bin/rotate-secrets apply` applies them. The `project-rotate-secrets` skill runs the apply.
- `just tf-plan`, `just tf-apply` and `just tf-output` run Terraform with the secrets from the store.
- `bin/tf-secrets names` lists the names the store holds, and `verify` compares the store with `terraform.tfvars`. Neither prints a value.
- `bin/tf-plan-diff <plan> [NAME]` lists what a saved plan changes, with no value.
- `bin/tfvars-missing.sh` lists the variables that the example file offers and your file does not set.
- `ls`, `stat` and `test` on a secret file are allowed, because they print no content.
- `rg` and `git grep` skip the gitignored secret files, so use them for a search over a folder.

## Before you deploy

Do these steps before every deploy, and do not skip them for a small release. Never print the probe token or a `terraform.tfvars` value.

1. Run `git fetch -q origin main`.
2. Read the live version from `/healthz`, as "Verify what is actually live" shows.
3. Count the gap with `git log --oneline --first-parent <live sha>..origin/main`.
4. Do "Check the release diff" and "Read the deploy notes of the release".
5. Show the owner the summary below.
6. Wait for the owner to say go. Do not deploy before that, also when the owner already asked for the deploy. The owner decided that this summary is a required approval gate on every deploy.

Write the summary as Markdown in this shape:

```markdown
**Deploy `<origin/main sha>` to production?**

Production runs `<live sha>`. This release adds <M> pull requests.

**What ships**
- <plain name of the change> (PR <number>, card <number>)

**Risk: <low | medium | high>**
- <each migration, with its risk, or "No migration">
- <each risky file, with its deploy note or **no note**, or "No change to env, Terraform, CSP, messenger or prod compose">
- <each deploy note step, or "No deploy notes">

**Steps**
1. Switch the checkout to `main` at `<origin/main sha>`.
2. <each deploy note step that comes before the code, such as a `terraform apply`>
3. Run `just deploy`.
4. Run the checks of "After a deploy", and <each check the deploy notes ask for>.
```

Give each change a plain name that a person understands without the diff. Group the pull requests by theme when there are many. Name an epic once, with the count of its parts. Write each empty item as a sentence, such as "No migration", and never as "none". Choose the risk level yourself, and let the bullets under it show the reasons. Leave out a step that does not apply.

An empty gap means that production already runs `origin/main`. Say so, and do not deploy.

### Check the release diff

Do this check together with the deploy notes. It finds a deploy item that has no note.

```bash
git fetch -q origin main
git diff --name-only <last deployed sha>..origin/main -- migrations/ .env terraform/ \
  config/packages/messenger.yaml config/packages/nelmio_security.yaml docker/compose/prod.yaml
```

Put every file of this list under **Risk** in the summary, also a file that a deploy note covers. Read each file that no deploy note covers, and mark it **no note**. Say whether the release ships a migration.

### Read the deploy notes of the release

1. Run `bin/agents/release-cards`. With no argument, it reads the live version from `/healthz`. Otherwise, give it the deployed sha.
2. Read the output. Each line is `<pr number>`, `<card ids>` and `<subject>`, separated by tabs. A card column can hold several ids, separated by commas.
3. Call `card_get` through the `loupe` MCP for each card id.
4. For an epic, also call `card_get` for each child in `children`. A standalone child owns its own deploy notes.
5. Call `document_get` for each linked document. Keep the documents tagged `deploy-notes`.
6. Make a deploy checklist from those documents.
7. Find each line with `-` or `?` as the card. A `?` means that `gh pr view` failed. Read the diff of that pull request, or of that commit, yourself. Never guess a card.

## Ship code

```bash
just deploy          # build-prod, push-prod, then create-deployment --force-rebuild --wait
```

Or, when the image is already pushed:

```bash
doctl apps create-deployment "$(just tf-output -raw app_id | tail -1)" --force-rebuild --wait
```

`--force-rebuild` makes the platform fetch the tag again. Without it, a deployment can complete, report `ACTIVE`, and run the previous image.

The working tree must be clean. `APP_VERSION` comes from `git describe --tags --always --dirty`, so an uncommitted change ships an image whose version no commit traces. `just release` refuses an unclean tree. `just deploy` does not, so check it yourself.

## Ship an infrastructure change

```bash
just tf-plan -out=deploy.tfplan                       # read it, then apply the file you read
just tf-apply deploy.tfplan; s=$?; rm -f terraform/deploy.tfplan; (exit $s)
```

Apply the saved plan, and do not plan again, so that what lands is what you reviewed. Terraform writes every secret into a saved plan in plain text. The apply command therefore removes the file, also when the apply fails. If a session ends between the two commands, delete `terraform/deploy.tfplan` by hand.

Read the plan as JSON, never as text. The DigitalOcean provider collapses the app resource into "(N unchanged blocks hidden)", and an added environment variable hides in there. A grep of the text output for a variable name finds nothing and reads as "no change". Use the script instead:

```bash
bin/tf-plan-diff terraform/deploy.tfplan MY_VAR
```

The script prints each changed resource, and whether `MY_VAR` appears before and after. It prints no value. Never run `terraform show -json` on a saved plan yourself. The hook refuses it, because the JSON holds the secrets.

To ship code and an infrastructure change together, apply the plan first. Then run `just deploy`, or `create-deployment --force-rebuild` when the image is already pushed. That makes two rollouts. The deployment of the apply keeps the cached image, also when you pushed a new `prod` tag before it.

## Verify what is actually live

Ask the app, because the platform cannot tell you. When `doctl apps get` reports `ACTIVE`, a deployment finished. That does not say which image runs, and the previous deployment also stays `ACTIVE` until the new one replaces it.

```bash
just healthz
```

The recipe loads the secrets from the store, and reads the token and the domain through `terraform console`. It sends the token to `curl` on standard input, and prints the body. It never prints the token. It answers `{"status":"ok","version":"<short sha>"}`. Compare that against `git rev-parse --short HEAD`. Without the header, the endpoint reports liveness alone and no metadata, by design.

Do not scrape `/about` for this. It renders the same version behind a session and an edge cache, so it is harder to read and less trustworthy.

`/admin/status` runs the `DiagnosticInterface` checks and needs an admin session. `TrustedProxyCheck` reports there, so read it after any change to `TRUSTED_PROXIES` or to the edge.

## Which deployment is running, and why

```bash
doctl apps list-deployments "$(just tf-output -raw app_id | tail -1)" -o json \
  | python3 -c "
import json,sys
for d in json.load(sys.stdin)[:3]:
    print(d['id'][:8], d['phase'], d['created_at'][:19], d.get('cause',''))
"
```

Read the `cause` field. `app spec updated` came from a `terraform apply` and may run old code. `manual` came from `create-deployment`.

## Migrations

`enable_predeploy_migrations = true`, so a pre-deploy job runs `doctrine:migrations:migrate` before the new containers take traffic. A failed migration fails the deployment, and the previous one keeps serving.

"Check the release diff" lists the migrations of the release. A release with no migration is a much smaller risk than one with a migration. Say which you ship.

## Trusted proxies

`app.loupe.ac` and `loupe.ac` sit behind Cloudflare, which sits in front of the private ingress of App Platform. `TRUSTED_PROXIES` must list the Cloudflare ranges. Otherwise Symfony stops the address walk at the ingress, and every visitor resolves to a Cloudflare address.

A test does not easily show this failure. The immediate peer is private and therefore trusted, so `isFromTrustedProxy()` answers true and the configuration looks right. `getClientIp()` still returns the edge. Two rate limiters key on that address, so they collapse to one bucket per edge node.

Terraform prepends `PRIVATE_SUBNETS` to the value of `trusted_proxies`, so set the Cloudflare ranges alone. Refresh them from `cloudflare.com/ips-v4` and `/ips-v6` when they change. `TrustedProxyCheck` on `/admin/status` tells you when the list is stale.

## After a deploy

`docs/operating/post-deploy-checks.md` is the list for operators. Run every check below that an agent can run, then report. Do not stop at the first pass.

```bash
APP=$(just tf-output -raw app_id | tail -1)
just healthz                                                            # version is the target sha
curl -sS -o /dev/null -w '%{http_code}\n' https://app.loupe.ac/livez    # 200
curl -sS -o /dev/null -w '%{http_code}\n' -X POST https://app.loupe.ac/mcp  # 401, not 404 or 403
doctl apps logs "$APP" migrate --type deploy --tail 20                  # the version it migrated to
doctl apps logs "$APP" --type run --tail 300 | grep -iE 'error|critical|" 5[0-9]{2} '
```

Run the two console checks in the `web` component. `doctl apps console` needs a TTY, so give it one with `script`. The console prompt takes the commands after a short wait:

```bash
(sleep 8; printf 'bin/console doctrine:migrations:status --no-ansi | tail -15; bin/console health-check:status --no-ansi; echo HC_EXIT=$?; exit\n'; sleep 40) \
  | script -q /dev/null doctl apps console "$APP" web 2>&1 | tail -50
```

- `doctrine:migrations:status` must show `New` as 0 and `Next` as `Already at latest version`. `Current` must be the newest file in `migrations/` on the target sha.
- `health-check:status` exits 0 on a warning. Report each row that is not `OK`. An `UNKNOWN` row is not a pass.
- When the console does not connect or prints no table, report both checks as not checked, with the error line.

Then do these checks:

- Read the newest deployment, as "Which deployment is running, and why" shows. It must be `ACTIVE` with cause `manual`.
- Compare the version in the `migrate` log with the newest file in `migrations/` on the target sha. A release with no migration logs that it is already at the latest version. This log shows that the pre-deploy job ran. The console check above shows that no migration is pending.
- Read the run log matches. Ignore lines from before the new deployment started.
- Call `search_issues` through the Sentry MCP with `is:unresolved firstSeen:-1h environment:prod` on the `loupe` project. Read each new issue.
- Run each check that the deploy notes ask for and that an agent can run.

### Do every deploy-note step yourself

Do each step of the deploy notes that an agent can do, and do not ask the owner again. The go of the owner on the summary covers them. Hand a step to the owner only when it needs a browser, a session, or a secret that a hook refuses to you.

- For a CLI change, run `git diff --name-only "$(git describe --tags --match 'cli/v*' --abbrev=0)"..HEAD -- cli/`. When it prints a file, push the next `cli/v*` tag at the deployed sha. Then wait for the `CLI release` workflow. Never move a tag, so a failed release gets the next patch tag.
- For a bridge update, read `~/Library/Application Support/loupe/rules.yaml`, and copy it to a backup file next to it. Edit it when the release changes its format. Then run `loupe update`, and read `loupe --version` and the end of `bridge.log`.
- A running bridge reads the file only at start and on `loupe bridge reload`. The update preflight reads the file on disk, so edit the file first and update after. Never run `loupe bridge stop` for an update, because it ends every running worker.
- Run a backfill or a console command in the `web` console, as above.

An agent cannot run the checks below. Give them to the owner:

- Open `/admin/status` with an admin session. Read the failed checks and the Trusted proxies row.
- Start a data export, then follow the download link in the email to a ZIP. That proves the worker and the archive path.
- Do each deploy-note check that needs a browser or a session.

Report as Markdown in the shape of the pre-deploy summary:

```markdown
**Deploy of `<target sha>`: <live | live, with <N> warnings | FAILED>**

Production runs `<live sha>`, which <matches | DOES NOT match> the target. The deployment `<id>` is `<phase>` with cause `<cause>`.

**Checked**
- <each check that passed, with one line of evidence>
- <each check that failed or is not OK, in bold, with its evidence>

**Not checked**
- <each check that you could not run, and why>

**For you**
1. <each step the owner must do, in order>
```

Write each empty section as a sentence, such as "Every check ran".

## Symptoms and causes

| Symptom | Cause |
|---|---|
| Deployment `ACTIVE`, `/healthz` reports the old version | A `terraform apply` deployment reused the fixed tag. Run `create-deployment --force-rebuild`. |
| `terraform plan` shows no environment change you know you made | The text output hid it. Read the JSON plan. |
| `/healthz` returns liveness with no version | The `X-Probe-Token` header is missing or wrong. |
| Rate limits fire for unrelated visitors | `TRUSTED_PROXIES` does not cover the edge, so they share one bucket. |
| The image reports a `-dirty` version | It was built from an unclean tree. Commit, rebuild, push. |
