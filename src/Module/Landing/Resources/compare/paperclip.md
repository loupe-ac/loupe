---
competitor: "Paperclip"
title: "Loupe vs Paperclip"
description: "Paperclip manages teams of AI agents for any work, with budgets and approval gates. Loupe reviews one software feature at a time. See the comparison."
comparedOn: 2026-10-26
competitorVersion: "v2026.1023.0 (2026-10-24), repository read 2026-10-26"
---

## In short

Paperclip manages a team of AI agents for many kinds of work, with an org chart, budgets and approval gates. Loupe takes one software feature from idea to merged pull request, and stops for your review where you decide. Both are open source and you can host both yourself. Pick Paperclip when you run many agents across many kinds of work. Pick Loupe when you build software with Claude Code or Codex and want to review the design and the code before anything merges.

## What Paperclip does well

- It supports many agents. The README names OpenClaw, Claude Code, Codex, Cursor, Gemini CLI, OpenCode, Pi, Hermes, Grok Build and Kimi Code. [2]
- It can run agents in cloud sandboxes. The README roadmap lists e2b, Cloudflare, Daytona, Modal, Novita and self-hosted Kubernetes as done. [2]
- It supports teams: several human users with roles, and many organizations in one deployment. [2]
- It controls spend. Budgets exist per company, agent and project, with threshold alerts and pauses at configured limits. [2]
- It covers work outside code, such as research, operations and content work. [2]
- Issues can pass a review stage and an approval stage. The reviewer can be an agent or a person, and can request changes. [4]
- Documents have revision history and comments on specific passages. [2]
- Its licence is MIT. [1]
- It is large and active. It has about 107,600 GitHub stars and a release every week or so. [1] [3]

## What Loupe does well

- Each card gets a tech design that you approve before a worker writes code. A product design with a clickable prototype can come first. That stage is optional. [L1]
- Documents keep versions and diffs. You comment on any line, accept suggestions and answer decision blocks. [L2]
- A second agent reviews each pull request against the approved tech design. Fix rounds run until the review passes. [L1]
- An optional live review lets you point at an element on a preview and leave a note on the card. [L1]
- Analytics show the cost per finished card, merge rate, fix rounds and hours to merge. Experiments compare models. [L3]
- A self-hosted Loupe instance sends no usage data. [L4]

## Side by side

| Dimension | Loupe | Paperclip |
|---|---|---|
| What it is | A software factory for coding agents: product design, tech design, code and review in one workflow. | "The app people use to manage AI agents for work". [2] |
| Workflow | One card per feature. Each card gets a tech design, an implementation and an agent review. Product design and live review are optional. Rules move the card and stop where a person decides. | You set goals, hire agents into roles and approve plans. Agents wake on a heartbeat, a schedule or an event. [2] |
| Document and spec review | Rendered documents with versions, diffs, line comments, suggestions and decision blocks. | Revision history and comments on passages. Plan approvals are on the README roadmap as done. We did not find decision blocks. [2] |
| Code review | A second agent reviews the pull request against the approved design. | A review stage can name an agent or a person. The runtime excludes the original executor from reviewing. Agent review loops stop after 3 rounds by default, then a human decides. [4] |
| Agent runtimes | Claude Code and Codex at any stage. Other models through OpenRouter with a Codex profile. Any MCP client can read and write the board. | About ten agents, plus anything that can receive a heartbeat. [2] |
| Where agents run | On your Mac or Linux machine through the `loupe bridge` CLI, on your own subscriptions. | On the Paperclip host, or in sandboxes. Availability depends on the configured environment and adapter. [2] |
| Team support | One user per project. No teams yet. | Several human users with roles. Many organizations per deployment. [2] |
| Cost tracking | Cost per finished card, merge rate, fix rounds, hours to merge. | Spend tracking with budgets, alerts and pauses at limits. [2] |
| Licence | AGPL-3.0-or-later. | MIT. [1] |
| Telemetry | A self-hosted instance sends no usage data. | Anonymous usage telemetry is on by default. You can turn it off. [2] |
| Release state | No versioned app release yet. The CLI has tagged releases. | v2026.1023.0 on 2026-10-24. [3] |

## Who should pick which

Pick Paperclip if:

1. You run many agents across many kinds of work, not only code.
2. You need budgets that stop a run at a limit.
3. You need several people on one instance with roles.
4. You want cloud sandboxes.
5. You want the MIT licence.

Pick Loupe if:

1. You build software with Claude Code or Codex.
2. You want to approve a design before code exists.
3. You want an agent to check each pull request against that design.
4. You want to leave notes on a live preview.
5. You want to see cost per finished card.

## Cost

Paperclip:

- Self-hosting: the software is free under the MIT licence. Start it with `npx paperclipai@latest onboard --yes` or with Docker. You pay for your own server and for the subscriptions or API keys of your agents. [2]
- Cloud: a waitlist is open, and the site says Paperclip is "rolling out gradually". We found no price for it. [5] [6]
- Paid tiers: none published. The home page has no pricing link, and we did not find a pricing page. [5]

Loupe:

- Self-hosting: the software costs nothing to license. You pay for your own server (web, worker and Postgres) and for the subscriptions or API keys of Claude Code or Codex. You also need a GitHub.com repository. [L4]
- Hosted version: a private beta with a waitlist. Loupe publishes no price for it. [L4]

## Sources

Competitor sources were read on 2026-10-26.

1. [GitHub API data for paperclipai/paperclip](https://api.github.com/repos/paperclipai/paperclip): 107,604 stars, MIT.
2. [Paperclip README](https://github.com/paperclipai/paperclip)
3. [Latest release](https://api.github.com/repos/paperclipai/paperclip/releases/latest), v2026.1023.0, published 2026-10-24.
4. [Docs: execution policy](https://docs.paperclip.ing/guides/power/execution-policy.md)
5. [paperclip.ing](https://paperclip.ing)
6. [paperclip.ing/waitlist](https://paperclip.ing/waitlist/)

Loupe sources are in the Loupe repository, read on 2026-10-26.

- L1. Landing page, workflow section. `docs/using/workflows.md`.
- L2. `docs/using/documents.md`.
- L3. `docs/using/analytics.md`.
- L4. Landing page, requirements and open-source sections. `docs/operating/install/index.md`. `docs/using/billing.md`.
