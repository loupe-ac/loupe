---
competitor: "LobeHub"
title: "Loupe vs LobeHub"
description: "LobeHub runs a team of AI agents with hosted plans. Loupe puts a person in the review loop of one coding agent workflow. See the comparison."
comparedOn: 2026-10-26
competitorVersion: "v2.2.20 stable (2026-10-18), repository read 2026-10-26"
---

## In short

LobeHub runs a team of AI agents for many kinds of work. Loupe takes a software feature from idea to merged pull request, and stops for your review at each design. Pick LobeHub if you want one workspace for many agents, many models and a team, with hosted plans and cloud sandboxes. Pick Loupe if you build software with Claude Code or Codex and want to approve the tech design before code exists.

## What LobeHub does well

- It supports teams. Workspaces have members, and tasks have assignments and mentions. [4] [5]
- It supports many agents and models. Release notes name Claude Code, Codex, Factory Droid, TRAE, Grok Build, Qoder and a DeepSeek harness, and the app offers its own hosted models. [4] [5] [6]
- It can run agents in cloud sandboxes with a persistent working directory, or on your own devices. [5]
- It reaches you outside the app. The README describes an IM gateway for chat apps, and the release notes name Telegram webhooks. [3] [4]
- A GitHub App sends pull request, CI and review events back to the agent that opened the pull request. [5]
- A delivery goes to an acceptance step, where a person accepts it or sends it back. [5]
- It has a free hosted plan today. [7]
- It has about 83,500 GitHub stars and ships a stable release every few weeks. [2] [6]

## What Loupe does well

- Each card gets a tech design that you approve before a worker writes code. A product design with a clickable prototype can come first. That stage is optional. [L1]
- Documents keep versions and diffs. You comment on any line, accept suggestions and answer decision blocks. [L2]
- A second agent reviews each pull request against the approved tech design. Fix rounds run until the review passes. [L1]
- A blocking question from an agent lands in your inbox, and a push tells you. When you answer, the bridge resumes the same agent session. [L3]
- An optional live review lets you point at an element on a preview and leave a note on the card. [L1]
- Analytics show the cost per finished card, merge rate, fix rounds and hours to merge. Experiments compare models. [L4]
- Loupe is AGPL-3.0-or-later. A self-hosted instance needs no outside service except GitHub.com. [L5]

## Side by side

| Dimension | Loupe | LobeHub |
|---|---|---|
| What it is | A software factory for coding agents: product design, tech design, code and review in one workflow. | "Your Chief Agent Operator". It "hires, schedules, reports on your entire AI team". [3] |
| Workflow | Each card gets a tech design, an implementation and an agent review. Product design and live review are optional. A person approves each design before the next stage. | Goals and tasks, with scheduling. A delivery goes to an acceptance step. [3] [5] |
| Document and spec review | Rendered documents with versions, diffs, line comments, suggestions and decision blocks. | Document comment threads and acceptance discussions. [4] We found no document versions or diffs. |
| Code review | A second agent reviews the pull request against the approved design. | A GitHub App routes CI failures and review feedback to the agent. Merged pull requests accept the delivery. [5] We did not check for an independent agent reviewer. |
| Agent runtimes | Claude Code and Codex at any stage. Other models through OpenRouter with a Codex profile. Any MCP client can read and write the board, documents and inbox. | Several agent CLIs and LobeHub's hosted models. [4] [5] [6] |
| Where agents run | On your Mac or Linux machine through the `loupe bridge` CLI, on your own subscriptions. No cloud sandboxes. | On your devices, or in LobeHub sandboxes. [5] |
| Team support | One user per project. No teams yet. | Members, task assignments and mentions. [4] |
| Cost tracking | Cost per finished card, merge rate, fix rounds, hours to merge. | Credit use and quota per plan. [7] We found no cost per task. |
| Licence | AGPL-3.0-or-later. | LobeHub Community License: Apache 2.0 with added conditions. A derivative work needs a commercial licence. [8] |
| Release state | No versioned app release yet. The CLI has tagged releases. | Stable v2.2.20 on 2026-10-18, with nightly builds almost every day. [6] |

## Who should pick which

Pick LobeHub if:

1. You work in a team and want members, assignments and mentions.
2. You want agents for many kinds of work, not only code.
3. You want cloud sandboxes, so work continues when your laptop is off.
4. You want a free hosted plan now.
5. You want to reach agents from chat apps.

Pick Loupe if:

1. You build software with Claude Code or Codex.
2. You want to approve a design before code exists.
3. You want an agent to check each pull request against that design.
4. You want to see cost per finished card.
5. You want a licence without a commercial condition for derivative works.

## Cost

LobeHub hosted plans: [7]

- Free: $0, with 500,000 credits per month.
- Starter: 5,000,000 credits per month.
- Premium: 15,000,000 credits per month.
- Ultimate: 35,000,000 credits per month.
- The pricing page shows two price sets for the paid plans. The plan cards show $9.90, $19.90 and $39.90 per month. The comparison table shows $12.90, $24.90 and $49.90 per month, and $118.80, $238.80 and $478.80 per year. The yearly totals divided by 12 match the card prices. The page does not say which price applies to which billing period.
- Enterprise: no listed price. The page lists a commercial licence, user management and self-hosted provider support. Contact LobeHub.
- Credits pay for LobeHub's hosted models. Extra credit packages exist.

LobeHub self-hosting: the README gives a Docker setup. [3] The software has no listed price. You pay for your own server and for model API keys. We did not verify that sandboxes, devices and the GitHub App work on a self-hosted instance.

Loupe:

- Self-hosting: the software costs nothing to license. You pay for your own server (web, worker and Postgres) and for the subscriptions or API keys of Claude Code or Codex. You also need a GitHub.com repository. [L5]
- Hosted version: a private beta with a waitlist. Loupe publishes no price for it. [L5]

## Sources

Competitor sources were read on 2026-10-26.

1. [LobeHub site](https://lobehub.com)
2. [GitHub API data for lobehub/lobehub](https://api.github.com/repos/lobehub/lobehub): 83,510 stars, 16,009 forks, licence reported as NOASSERTION.
3. [LobeHub README](https://github.com/lobehub/lobehub)
4. [Release v2.2.16](https://github.com/lobehub/lobehub/releases/tag/v2.2.16): task assignments, mentions, document comment threads, Telegram webhooks, Factory Droid, TRAE, Grok Build.
5. [Release v2.2.19](https://github.com/lobehub/lobehub/releases/tag/v2.2.19): GitHub App closed loop, acceptance, sandbox working directory, device ports and tunnels, Qoder.
6. [Latest release v2.2.20](https://api.github.com/repos/lobehub/lobehub/releases/latest), published 2026-10-18. The release list shows nightly builds on 2026-10-24 and 2026-10-25.
7. [LobeHub pricing](https://lobehub.com/pricing)
8. [LobeHub LICENSE](https://github.com/lobehub/lobehub/blob/main/LICENSE)

Loupe sources are in the Loupe repository, read on 2026-10-26.

- L1. Landing page, workflow section. `docs/using/workflows.md`.
- L2. `docs/using/documents.md`.
- L3. `docs/using/inbox.md`, `docs/using/notifications.md`.
- L4. `docs/using/analytics.md`.
- L5. Landing page, requirements and open-source sections. `docs/operating/install/index.md`. `docs/using/billing.md`.
