---
title: "Introduction"
description: "A software factory for coding agents: product design, tech design, code and review, in one workflow."
---

Loupe is a software factory for coding agents. Product design, tech design, code
and review run in one workflow.

You move a card on a board. The [workflow](using/workflows.md) asks your agents
for the work at each stage, and it stops where a person must decide. A
[bridge](extending/cli-bridge.md) on your machine starts the workers, so they run
on your own subscriptions. A second agent reviews each design and each pull
request. You review the [documents](using/documents.md) in the browser, and you
leave notes on a running preview with the [live review](using/live-review.md)
widget.

## Start here

- **[Getting started](getting-started/index.md)** connects your coding agent
  to an instance and takes you through a first review.
- **[Installing Loupe](operating/install/index.md)** gives five ways to run an
  instance, and helps you choose one.
- **[Using Loupe](using/documents.md)** — documents, the MCP endpoint, site
  review, the admin area.
- **[Extending Loupe](extending/reverse-proxy.md)** — optional infrastructure:
  reverse proxy, Mercure, object storage, social login, billing.
- **[Operating](operating/first-run.md)** — first run, recovery, backups, and
  what to check after a deploy.
- **[Reference](reference/environment.md)** — every environment variable and
  every console command.
- **[Known gaps](known-gaps.md)** — read this before a first real deploy.

## What it is built on

Symfony, Tailwind CSS and Symfony UX (Stimulus + Turbo). There is no component
library: the visual system is hand-rolled from design tokens and semantic
component classes in `assets/styles/app.css`.

Loupe runs anywhere that can host a container and a Postgres database. Nothing
in the application assumes a particular provider.

## Licence

AGPL-3.0-or-later. If you run a modified version as a network service, the
licence requires you to make your source available to its users — which is what
`APP_SOURCE_URL` and the footer link on every page exist for. See
[Environment variables](reference/environment.md).
