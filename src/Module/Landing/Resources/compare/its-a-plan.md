---
competitor: "It's a Plan"
title: "Loupe vs It's a Plan"
description: "Loupe and It's a Plan both put coding agents on a board. Compare their scope, agent model, licence and full cost, read on 2026-10-26."
comparedOn: 2026-10-26
competitorVersion: "v1.8.0 (2026-10-23)"
---

## In short

It's a Plan is a self-hosted issue tracker in which AI agents work as project members. Loupe is a board with a staged workflow, document review and an owner inbox for coding agents. Both are AGPL-3.0, both have a board and an MCP server, and both run coding agents on your own machine. [3] [5] They differ in scope. It's a Plan is a full tracker that agents can use. Loupe is built around the path from a card to a merged pull request.

## What It's a Plan does well

- It is a full tracker. It has board, table, timeline and calendar views, cycles, custom fields, dashboards, docs and 13 interface languages. [1] [3]
- Agents are project members. An internal agent runs on the instance with a model and tools that you choose. An external agent runs on your machine through `@itsaplan/runner`, which supports Claude Code, Codex, Antigravity CLI, GitHub Copilot CLI and opencode. [3]
- It links pull requests from GitHub, GitLab, Gitea, Forgejo and Bitbucket to issues. [3]
- It offers a REST API, signed webhooks and an MCP server. [3]
- It deploys with one click on Railway, and also with Docker Compose, Coolify or a Helm chart. [3]
- It moves fast. Version 1.8.0 shipped on 2026-10-23, and the repository has about 2.0k stars. [3] [4]

## What Loupe does well

- Loupe runs a card through a lifecycle. The Lifecycle template has product design, tech design, implementation, review and merge. Workflow rules move the card and ask a bridge for work. [5]
- A person reviews documents in Loupe. Product designs and tech designs are Markdown documents. The reviewer selects text, comments on it, and approves or requests changes. [5]
- The owner inbox collects questions, review requests and to-dos from agents. It can send a web push to a phone. [5]
- Site review adds the same select-and-comment flow to a live web page. [5]
- A command-line bridge runs workers on your machine with Claude Code or Codex. Loupe shows each run on its Activity page. [5]
- Loupe runs on one Docker Compose stack: web, worker and Postgres. [5]

## Side by side

The It's a Plan column comes from its home page, pricing page and README. [1] [2] [3] The Loupe column comes from the Loupe documentation. [5]

| Dimension | Loupe | It's a Plan |
|---|---|---|
| Main idea | Software factory: a card moves through a staged workflow | Issue tracker where agents are teammates |
| Licence | AGPL-3.0-or-later | AGPL-3.0, runner under Apache-2.0, commercial licence offered |
| Hosted service | Private beta, no public price | None stated; self-host only |
| Agents on your machine | Claude Code and Codex, through the bridge | Claude Code, Codex, Antigravity CLI, Copilot CLI, opencode, or a stdin command |
| Agents on the server | No | Yes, with the model and keys you choose |
| Tracker depth | Board with workflow rules | Board, table, timeline, calendar, cycles, custom fields, dashboards |
| Document review with comments | Yes | Docs with revision history; no review flow is stated |
| Agent questions to a person | Owner inbox | Not stated |
| Site review of live pages | Yes | Not stated |
| Forges for pull request links | GitHub reads state. Links from other forges are stored but not read. | GitHub, GitLab, Gitea, Forgejo, Bitbucket |
| MCP server | Yes | Yes |
| Languages | English | 13 |
| Maturity | Young | Warns of breaking changes before a stable release |

## Who should pick which

Pick Loupe if:

1. You want each piece of work to pass design, review and merge steps with a person at the gates.
2. You want agents to ask you questions in one inbox and to submit documents for review.
3. Your code is on GitHub and your agents are Claude Code or Codex.

Pick It's a Plan if:

1. You want one tracker for people and agents, with views, cycles and custom fields.
2. You want agents that run on the server, or a forge other than GitHub that reads pull request state.
3. You need a UI in a language other than English.

## Cost

A "$0" label hides real costs. This section counts them for both tools.

It's a Plan, self-hosted:

- Community is free under AGPL-3.0, with any number of people. You still pay for a server, a PostgreSQL database and S3-compatible storage. Its site states no figures for these. [2]
- Pro costs $24 a month or $240 a year for one instance on one domain. Pro adds an audit log, a workspace dashboard, AI budgets, more than one workspace, SAML sign-in per workspace and event-based agent schedules. [2]
- Its comparison table sets Pro at $240 a year for 10 people against per-seat prices of other tools. That table leaves out hosting. Its site also lists Pro features that are not yet shipped, so the $240 buys the current set only. [2]
- You pay your own model costs. Internal agents use your model keys, and external agents use your own agent subscriptions. [3]

Loupe:

- Self-hosting is free under AGPL-3.0. You pay for the server that runs the web app, the worker and Postgres. You also pay for the agent subscriptions or API usage that your workers use. Loupe does not state a server size or a monthly figure, and this page does not guess one.
- Hosted Loupe is in private beta. The repository shows no public price, so no price is known. The code has an optional Stripe billing flag that is off by default.

## Sources

Read on 2026-10-26.

1. [It's a Plan home page](https://itsaplan.dev)
2. [It's a Plan pricing page](https://itsaplan.dev/pro)
3. [It's a Plan repository and README](https://github.com/croffasia/itsaplan)
4. [It's a Plan release v1.8.0, from the GitHub API](https://api.github.com/repos/croffasia/itsaplan/releases/latest)
5. [Loupe repository and README](https://github.com/loupe-ac/loupe), and the [Loupe documentation](https://loupe.ac/docs/) on install, workflows, the inbox and worker runs

The page does not rest on a run of It's a Plan. The facts about it come from its own site and README.
