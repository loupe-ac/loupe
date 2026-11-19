---
title: 'Signing releases'
description: 'How CLI and skills releases are signed, how to rotate the release key, what to do when it leaks, and how to reinstall a bridge.'
---

Each CLI release and each skills release carries a file named `checksums.txt.sig`. It is a signature of `checksums.txt` with a release key. GitHub does not hold that key. The bridge updater, the skills refresh, `install.sh` and the Homebrew job check the signature before they trust `checksums.txt`. A release with no valid signature is never installed.

This page is for the person who releases Loupe. A user of the CLI reads [Installing the CLI](../getting-started/cli.md).

## The keys

Two keys are trusted from the first signed release: the active key, and a backup key that you keep offline. Their public lines sit in `cli/internal/update/release_keys`, in the `allowed_signers` format of `ssh-keygen`. `cli/install.sh` and the `cli-install-release` recipe of the `justfile` hold the same lines. A Go test fails when the three copies differ.

The private active key is one value in a sops file outside the repository, `~/.config/loupe/release-signing.enc.yaml`. It is encrypted to your age key, like `terraform/secrets.enc.yaml`. Keep the backup key off every machine that runs agents.

## Sign a release

A release workflow builds the archives and `checksums.txt`, attests `checksums.txt`, and publishes a draft release. Nobody can install a draft. After the owner says go on the deploy summary, the deploy agent signs the release. It runs two commands:

```bash
bin/agents/release-audit cli/v2.8.0 <deployed sha>
just sign-all cli/v2.8.0 --yes
```

`release-audit` is read-only. It looks for signs of a hijacked GitHub account: a commit on `main` with no merged pull request, a release or a workflow run that nobody expected, and a deploy key, secret, webhook, ruleset, environment, collaborator or open invitation that was added, removed or changed. It compares the access sets with a baseline in `~/.config/loupe/release-audit.json`, which only the `--accept` flag writes. On a surprise, or on a check that cannot read its data, the agent stops and shows the lines. It runs `--accept` only after the owner confirms each line.

`sign-all` signs the drafts of both skills packages, then the CLI draft. It checks every draft before it signs any draft:

1. The attestation of each `checksums.txt` must come from the release workflow, on a commit that is an ancestor of `origin/main`.
2. Each skills package that the CLI commit names must have a draft or a published release. A published release must carry a `checksums.txt.sig` that a key of `release_keys` made. A missing release or a bad signature refuses the whole run.

It asks `Sign and publish these N releases? [y/N]` once on the terminal. `--yes` skips that question and nothing else. It stops at the first refusal. A re-run signs only what is left.

To sign one release by hand, for example a skills release with no CLI release, run `just sign-release <tag>`. It reads its answer from the terminal. The recipe downloads `checksums.txt` of the draft once and checks its attestation. It prints the file sums and the commits since the last signed tag. It signs the same local file, uploads `checksums.txt.sig`, verifies it against `release_keys`, and publishes the draft. A CLI release then starts the `cli-homebrew.yml` workflow on `main`, which updates the Homebrew tap.

Read the printed commits before you approve. A signature proves that you released the code. It does not prove that the code is good.

### What the deploy agent can and cannot stop

The terminal question stops an accident. It does not stop a hijacked agent, because any process that runs as you can read the age key and decrypt the release key. The attestation check and the audit are the defence against a hijacked GitHub account or token. A hijacked agent on your machine can still sign, and can run `--accept`. Keep the agent sessions that sign on a machine that you trust.

The first audit run has no baseline, so it stops and lists the current deploy keys, secret names, webhooks, rulesets, environments and collaborators. Read them, confirm them in the chat, and the agent runs `--accept`. A planned change, such as a rotated secret, stops the next release once in the same way.

## Rotate the key on a plan

Rotate the key when you retire it, or on a schedule. Take these steps in order:

1. Make a new key pair. Add the public line to `release_keys`, `cli/install.sh` and the `cli-install-release` recipe.
2. Ship a CLI release that you sign with the old key. It teaches each bridge the new key.
3. Open the bridges page. Wait until the bridges you care about run that release or a later one.
4. Move the new public line to the first line of `release_keys` in every copy, and keep the old line below it. `just sign-release` signs with the first line, so the order must match the private key. Put the new private key in the sops file. Then sign the next releases.
5. Ship a later release that removes the old key from `release_keys`.

A bridge that missed the middle release in step 2 finds the newest release that a key it trusts signed. It installs that release, and then trusts the new key. This walk works only while the middle release stays inside the CLI range of the server. A bridge that was offline for the whole rotation needs a reinstall.

## When the active key leaks

An attacker with the key can sign a release that every bridge accepts. Act in this order:

1. Take the fingerprint of the stolen public key:

   ```bash
   ssh-keygen -lf <(echo 'ssh-ed25519 <the base64 blob of the key line>')
   ```

   The output starts with `SHA256:`. That text is the fingerprint.
2. Add the fingerprint to `REVOKED_RELEASE_KEYS` in `src/Module/Bridge/Service/CliCompatibility.php`, as one string in the list:

   ```php
   public const array REVOKED_RELEASE_KEYS = ['SHA256:mPVVTkHngFt3LMCmwB7Nw6BtoS371HG9RRKbhn6T4jM'];
   ```

3. Deploy the app. The heartbeat answer, `GET /api/v1/bridge/events` and `GET /api/versions` then carry the list as `revokedReleaseKeys`. The instance also serves `install.sh` without the lines of those keys.
4. Move the backup public line to the first line of `release_keys` in every copy. `just sign-release` signs with the first line. Put the backup private key in the sops file, and sign a CLI release with it. That release removes the stolen key from `release_keys`.

At its next check, each bridge of the instance stores the list. The list only grows, because an instance can only take trust away. A bridge refuses a release that a listed key signed. It deletes a staged binary from that key. It skips a stored skill version from that key, also for a session that resumes, and deletes it. Workers then start with the highest skill version left.

A self-hosted instance carries the list only after its operator deploys the new app version.

### Reinstall a bridge that ran a bad release

A bridge that already runs a CLI from the stolen key runs the attacker's code. The code can ignore the list and any later release. Only a reinstall recovers it.

Use the `install.sh` that your instance serves. That copy leaves out the stolen key lines. It skips each release that the stolen key signed, also a higher one, and installs the newest release that the backup key signed:

```bash
curl -fsSL https://<your Loupe instance>/install.sh | sh
```

To skip that walk, pin the release that the backup key signed:

```bash
curl -fsSL https://<your Loupe instance>/install.sh | sh -s -- --version <version>
```

Do not use a copy of `install.sh` from the repository, or the `just` recipe. They trust the stolen key until the backup release removes it.

The bridges page shows the CLI version of each bridge, so you can find the ones to reinstall.
