# DigitalOcean App Platform deployment

This is a **thin root** that deploys the app via the shared module
[`terraform-digitalocean-symfony-app`](https://github.com/ubermuda/terraform-digitalocean-symfony-app)
(pinned to a tag in `main.tf`). All the deployment logic — the App Platform spec,
the per-app database on the shared cluster, the optional domain and migration
job — lives in the module. Here you set only this project's values and secrets.

Adjusting the deployment? Edit the `module "app"` block in `main.tf`
(`app_name`, `db_name`/`db_user`, optional `custom_domain`, `extra_env`) and
provide the secrets below.

`spaces.tf` additionally creates the object storage bucket that data-export
archives live in, because the web and worker containers do not share a
filesystem. That part is DigitalOcean-specific; the application is not — it
speaks plain S3 and can be pointed at AWS, MinIO or R2 by setting
`create_export_bucket = false` and filling in the `export_storage_*` variables.

## Deploy

```bash
just build-prod && just deploy    # build linux/amd64 image, push, roll out

# terraform (from the repository root):
cp terraform/terraform.tfvars.example terraform/terraform.tfvars   # values that are not secret
just tf-init        # fetches the pinned module
just tf-apply       # loads the secrets from the store, then runs terraform apply
```

`just tf-apply` runs `terraform apply` through `bin/tf-secrets exec`. A bare `terraform apply` gets no secret from the
store. Put the secrets in the store first, as the "Secrets" section says. A first install generates the two app keys:

```bash
printf '%s' "$(openssl rand -hex 16)" | bin/tf-secrets set TF_VAR_app_secret
printf '%s' "$(php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());')" | bin/tf-secrets set TF_VAR_app_encryption_key
```

## Secrets

`secrets.list` names the Terraform inputs that are secrets. Two ways pass them to Terraform:

- **With a secret store.** The values live in `secrets.enc.yaml`, a [sops](https://getsops.io) file encrypted with an
  [age](https://age-encryption.org) key. `bin/tf-secrets exec -- <command>` decrypts them into the environment of one
  command as `TF_VAR_*` variables. The `just tf-*` recipes, `just deploy`, `just logs-prod` and `just healthz` call it for you.
  `terraform.tfvars` then holds only values that are not secret: placement, domains, client ids and URLs.
- **Without a store.** When `secrets.enc.yaml` does not exist, `bin/tf-secrets exec` runs the command as is. Put the
  secrets in `terraform.tfvars`, or export them as `TF_VAR_*` variables before `just tf-apply`.

Set up the store once:

```bash
age-keygen -o ~/Library/Application\ Support/sops/age/keys.txt   # prints the public key
export SOPS_AGE_RECIPIENTS=age1...                              # the public key
printf '%s' "$VALUE" | bin/tf-secrets set TF_VAR_app_secret     # first call creates secrets.enc.yaml
bin/tf-secrets import     # copy every listed secret from terraform.tfvars into the store
bin/tf-secrets verify     # every name must read `same` or `unset`
just tf-plan              # must show no change
```

Then delete the secret lines from `terraform.tfvars`. `bin/tf-secrets names` lists the names the store holds. It prints no
value. `printf '%s'` keeps a trailing line break out of the value, and a builtin keeps the value out of the process list.
`secrets.enc.yaml` is gitignored. Back up the age key file: a lost key means a full rotation of every secret.

`just rotate-secrets` collects new values into a pending store, `secrets.pending.enc.yaml`. `bin/rotate-secrets apply` then writes them into the store for the listed secrets, and into `terraform.tfvars` for the rest.

`just build-prod`/`just deploy`/`just logs-prod` (in the project `justfile`) build
and roll out the image; the `prod_image` variable there must match the module's
`registry`/`image_repository`/`image_tag`.

## Postgres: bring your own cluster, or have Terraform create one

Pick one — `terraform apply` fails at plan time if you pick neither or both.
`region` is required either way.

**Attach to a cluster you already run.** Terraform creates a database and a user
*on* it:

```hcl
db_cluster_name = "loupe-db"
```

```bash
doctl databases create loupe-db --engine pg --region tor1  # if you have none
doctl databases list      # the Name column is db_cluster_name
```

**Or have Terraform create a dedicated one**, leaving `db_cluster_name` unset:

```hcl
create_db_cluster = true
db_cluster_region = "tor1"   # datacenter slug, NOT the App Platform region
```

Sized by `db_cluster_size` (default `db-s-1vcpu-1gb`) and
`db_cluster_node_count` (default `1` — no standby). This mode also manages the
cluster's trusted sources, and manages them **authoritatively**: the firewall
resource replaces the whole list on every apply, so a rule appended with `doctl`
is silently dropped later — including the app's own. `just tf-db-bootstrap`
detects this mode and skips its firewall half; the supported route is
`db_cluster_trusted_ips`. Its GRANT half still runs, and is still needed.

**Setting neither is rejected on purpose.** An empty `db_cluster_name` falls
through to the shared module's own historical default, which is the upstream
project's cluster rather than yours — so the choice is forced rather than
defaulted.

`db_cluster_region` is a **datacenter** slug (`tor1`, `nyc3`); `region` is an
**App Platform** slug (`tor`, `nyc`). They are different namespaces, and the
wrong one plans cleanly and fails at apply.

## First deploy needs a one-time DB bootstrap

The cluster needs a `GRANT` of schema privileges, which DigitalOcean's API
exposes no resource for. Attaching to a cluster you already run also needs this
app + your IP added to its trusted sources, appended rather than declared so
sibling apps sharing the cluster are not cut off. After the first
`just tf-apply`, run:

```bash
just tf-db-bootstrap
```

In dedicated mode (`create_db_cluster = true`) the recipe runs the GRANT only,
because Terraform owns the trusted-source list there. If this host cannot reach
the cluster, add its address to `db_cluster_trusted_ips`, apply, re-run the
recipe, then empty the variable and apply again.

Then run migrations once and set `enable_predeploy_migrations = true` in
`terraform.tfvars` for automated migrations thereafter. (The manual equivalent
is in the module's README → "Manual database bootstrap".)

## Notes

- **State is sensitive** (it holds the plaintext of every SECRET env var, and now
  also the Spaces key's secret). The secret store does not change this: the
  DigitalOcean provider has no way to keep a value out of the state. The local
  state stays on disk in plain text. For this project, a hook refuses agent reads of it, of
  `terraform state pull` and of a saved plan. An operator with more than one machine should use an
  encrypted remote backend — see the commented block in `versions.tf`.
- **A saved plan holds the secrets in plain text** until you apply it. Save it as
  `deploy.tfplan` (`just tf-plan -out=deploy.tfplan`), apply it with `just tf-apply deploy.tfplan`, and delete it.
- **Region** has no default: set `region` to your cluster's region, and
  `export_bucket_region` to a Spaces datacenter. They use different slugs —
  App Platform's `tor` is Spaces' `tor1`, `nyc` is `nyc3`.
- Module inputs, outputs, and limitations are documented in the module repo.
