---
competitor: "Multica"
title: "Loupe vs Multica"
description: "Multica gives coding agents a seat on your team. Loupe adds a review step at each stage. See how they compare on workflow, runtimes, licence and cost."
comparedOn: 2026-10-26
competitorVersion: "v0.7.0 (2026-10-23), repository read 2026-10-26"
---

## In short

Multica and Loupe both put coding agents on a board and open pull requests on your repository. Multica treats agents as teammates in a shared workspace. Loupe adds a review step at each stage, from the design to the merged change. Pick Multica if you run a team, use many agent CLIs, or want agents in chat apps. Pick Loupe if you work alone on a GitHub.com repository and want to approve the design and the result before a change merges.

## What Multica does well

- It supports 26 agent CLIs, among them Claude Code, Codex, Cursor, Copilot, OpenCode, Grok, Kimi and Qwen Code. [2]
- It supports teams: workspaces, the roles owner, admin and member, and squads of people and agents. [2]
- It connects to Slack, Feishu/Lark, DingTalk, WeCom and Telegram. DingTalk, WeCom and Telegram are community-maintained. [2]
- It connects to GitHub, GitLab, Gitea and Forgejo, self-hosted servers included. [2] [4]
- It has desktop apps for macOS, Windows and Linux. The iOS app builds from source and is not on the App Store yet. [2]
- It shows usage per run, per agent and per issue. [2]
- It ships often. The README says "We release most weekdays". [2]
- A hosted cloud with a free trial exists today. [3]

## What Loupe does well

- An agent writes a tech design, and you approve it before a worker builds anything. A product design with a clickable prototype can come first. That stage is optional. [L1]
- Documents keep versions and diffs. You comment on any line, suggest edits and answer decision blocks. [L2]
- A second agent reviews each pull request against the approved tech design. Fix rounds run until the review passes. [L1]
- A blocking question lands in your inbox. When you answer, the bridge resumes the same agent session. [L3]
- An optional live review lets you point at an element on a preview and leave a note on the card. [L1]
- Loupe has an MCP server. Any MCP client can read and write the board, documents and inbox. [L4]
- Analytics show the cost per finished card. Experiments compare models. [L5]

## Side by side

| Dimension | Loupe | Multica |
|---|---|---|
| What it is | A software factory for coding agents: product design, tech design, code and review in one workflow. | "Make humans and AI agents work as one team" (GitHub description, which also says open-source and self-hostable). The licence adds conditions. [1] [7] |
| Workflow | Each card gets a tech design, an implementation and an agent review. Product design and live review are optional. Rules move the card and stop where a person decides. | You assign an issue to an agent. By default the agent sets "in progress", then "in review". The issue moves to done when its linked pull requests merge, if the workspace enables this. Admins can add custom statuses. [6] |
| Document and spec review | Rendered documents with versions, diffs, line comments, suggestions and decision blocks. | None found in the README or the issue docs. |
| Code review | A second agent reviews the pull request against the design. | The issue shows the line counts of each linked pull request. The integration "never pushes commits, comments, or status checks". We found no agent code review. [4] |
| Agent runtimes | Claude Code and Codex at any stage. Other models through OpenRouter with a Codex profile. | 26 agent CLIs. [2] |
| Where agents run | On your Mac or Linux machine through the `loupe bridge` CLI. No cloud sandboxes. | A daemon on your computer or a server you control. A cloud runtime is on a waitlist. [3] |
| Team support | One user per project. No chat channels, no mobile app. | Workspaces, three roles, squads, chat channels. [2] |
| Cost tracking | Cost per finished card, merge rate, fix rounds, hours to merge. | Usage analytics per run, per agent and per issue. [2] |
| Licence | AGPL-3.0-or-later. | The Multica License: Apache 2.0 plus conditions. A hosted service for third parties needs a commercial licence, even when it is free. [7] |
| Telemetry | A self-hosted instance sends no usage data. | A self-hosted server sends one anonymous snapshot a day. `DO_NOT_TRACK=1` turns it off. [2] |
| Release state | No versioned app release yet. The CLI has tagged releases. | v0.7.0 on 2026-10-23, v0.6.5 on 2026-10-21. [8] |

## Who should pick which

Pick Multica if:

1. You run a team and need roles and shared workspaces.
2. You use an agent CLI that Loupe does not run.
3. You want agents in Slack or on your phone.
4. Your code is on GitLab, Gitea or Forgejo.
5. You want a hosted cloud with a trial today.

Pick Loupe if:

1. You work alone on a GitHub.com repository.
2. You want to approve a design before code exists.
3. You want an agent to check each pull request against that design.
4. You want an MCP server for your board and documents.
5. You prefer an AGPL-3.0 licence to a licence with added hosting conditions.

## Cost

Multica:

- Self-hosting inside your organisation: the software needs no licence fee. You pay for your own server and for agent subscriptions. Docker Compose, Helm and an install script are the options. [2]
- Hosting Multica for outside parties needs a commercial licence, with or without a fee. We found no price for it. [7]
- Hosted cloud: the site links a free trial, a cloud runtime waitlist and "talk to sales". The pricing page `multica.ai/pricing` returned 404. We found no paid-plan price and no trial length. [3] [5]

Loupe:

- Self-hosting: the software costs nothing to license. You pay for your own server (web, worker and Postgres) and for the subscriptions or API keys of Claude Code or Codex. You also need a GitHub.com repository. [L6]
- Hosted version: a private beta with a waitlist. Loupe publishes no price for it. [L6]

## Sources

Competitor sources were read on 2026-10-26.

1. [GitHub API data for multica-ai/multica](https://api.github.com/repos/multica-ai/multica): 53,876 stars, licence reported as NOASSERTION.
2. [Multica README](https://github.com/multica-ai/multica)
3. [multica.ai](https://multica.ai)
4. [Docs: GitHub integration](https://multica.ai/docs/github-integration)
5. [multica.ai/pricing](https://multica.ai/pricing), HTTP 404 on 2026-10-26.
6. [Docs: issues](https://multica.ai/docs/issues)
7. [Multica LICENSE](https://github.com/multica-ai/multica/blob/main/LICENSE)
8. [Multica releases](https://api.github.com/repos/multica-ai/multica/releases?per_page=6)

Loupe sources are in the Loupe repository, read on 2026-10-26.

- L1. Landing page, workflow section. `docs/using/workflows.md`.
- L2. `docs/using/documents.md`.
- L3. `docs/using/inbox.md`.
- L4. Landing page, works-with section. `docs/using/mcp.md`.
- L5. `docs/using/analytics.md`.
- L6. Landing page, requirements and open-source sections. `docs/operating/install/index.md`. `docs/using/billing.md`.
