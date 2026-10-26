---
competitor: "Vibe Kanban"
title: "Loupe vs Vibe Kanban"
description: "Vibe Kanban's company shut down in April 2026. See how the open-source app compares with Loupe on workflow, review, agents, cost and licence."
comparedOn: 2026-10-26
competitorVersion: "v0.1.44 (2026-04-24), repository read 2026-10-26"
---

## In short

The company behind Vibe Kanban, bloop, announced its shutdown on 2026-04-10. The app stays open source under Apache-2.0, and contributors still commit to it. Pick Vibe Kanban if you want one local command that runs many coding agents in parallel, and you review each diff yourself. Pick Loupe if you want each card to pass a tech design and an agent review before you read the code.

## What Vibe Kanban does well

- It starts with one command, `npx vibe-kanban`, and runs on your machine. [3]
- It supports ten coding agents, among them Claude Code, Codex, Gemini CLI, GitHub Copilot, Amp, Cursor and OpenCode. [3]
- Each workspace has its own branch, terminal and dev server. [3]
- You review diffs in the app. An inline comment goes straight back to the agent. [3]
- It has a built-in preview browser with devtools, inspect mode and device emulation. [3]
- You can merge a workspace branch locally, so it does not need GitHub. [5]
- Its licence is Apache-2.0, which has fewer conditions than AGPL-3.0. [2]
- It has about 28,500 GitHub stars and a long release history. [2]

## What Loupe does well

- Each card has a board state: working, waiting, stuck or needs you. Epics get their own lane. [L1]
- An agent writes a tech design, and you approve it before a worker builds anything. A product design with a clickable prototype can come first. This stage is optional. [L2]
- You comment on any line of a document, suggest edits and answer decision blocks. Documents keep versions and diffs. [L3]
- A second agent reviews each pull request against the approved tech design. Fix rounds run until the review passes. [L2]
- A blocking question from an agent lands in your inbox. When you answer, the bridge resumes the same agent session. [L4]
- Analytics show the cost per finished card, merge rate, fix rounds and hours to merge. Experiments compare models. [L5]
- An optional live review lets you point at an element on a preview and leave a note on the card. [L2]

## Side by side

| Dimension | Loupe | Vibe Kanban |
|---|---|---|
| What it is | A software factory for coding agents: product design, tech design, code and review in one workflow. | A local app that runs coding agents in parallel workspaces. [3] |
| Workflow | Each card gets a tech design, an implementation and an agent review. Product design and live review are optional. Rules move the card and stop where a person decides. | You start a workspace, the agent works, you review the diff, then you open a pull request or merge. [3] [5] |
| Kanban board | Yes. Cards, states and epic lanes. | The README describes a board. Since release v0.1.44, the project pages show an export screen instead of the interactive board. [3] [4] |
| Document and spec review | Rendered documents with versions, diffs, line comments, suggestions and decision blocks. | None found in the README or on the site. |
| Code review | A second agent reviews the pull request. You read the code on GitHub. | Diff view with inline comments inside the app. [3] |
| Agent runtimes | Claude Code and Codex at any stage. Other models through OpenRouter with a Codex profile. Any MCP client can read and write the board, documents and inbox. | Ten agents named in the README. [3] |
| Where agents run | On your machine through the `loupe bridge` CLI on Mac or Linux. No cloud sandboxes. | On your machine, each workspace on its own branch. [3] |
| Team support | One user per project. No teams yet. | Team and organisation features were remote services, and the shutdown post says the company removes them. [1] |
| Cost tracking | Cost per finished card, merge rate, fix rounds, hours to merge. | None found in the README or on the site. |
| Licence | AGPL-3.0-or-later. | Apache-2.0. [2] |
| Company state | In active development. | The company shut down on 2026-04-10. The community maintains the project. [1] |
| Release state | No versioned app release yet. The CLI has tagged releases. | Latest release v0.1.44 on 2026-04-24. A version bump to 0.1.45 landed on 2026-09-19 with no release. Commits continued on 2026-10-26. [2] [6] |

## Who should pick which

Pick Vibe Kanban if:

1. You want one command and a local app with no server.
2. You use an agent that Loupe does not run, such as Gemini CLI, Amp or Cursor.
3. You want to review diffs and a preview in one window.
4. You need to work without GitHub.
5. You accept that no company stands behind the project.

Pick Loupe if:

1. You want to approve a design before any code exists.
2. You want an agent to check each pull request against that design.
3. You want a board with states, an inbox and cost per card.
4. Your repository is on GitHub.com and you use Claude Code or Codex.
5. You work alone, because Loupe has no teams yet.

## What the shutdown means for you

The shutdown post of 2026-04-10 says local workspaces keep working. It says the project "will live on, open source and community maintained". [1] The company ended its remote services (kanban issues, comments, projects and organisations) 30 days after the post, and refunded invoices of the last 30 days. [1]

The latest release is v0.1.44 from 2026-04-24, and the npm `latest` tag is the same version. [2] [7] Contributors still commit: the most recent commits are dated 2026-10-26, and users still open issues. [6] [8] The shutdown post promised a roadmap for the community edition. We did not find one on 2026-10-26. The README and the getting-started docs still describe the board and team features, so read them with care. [3] [5]

## Cost

Vibe Kanban software:

- Free plan: $0 per month, one user. [9]
- Pro plan: $30 per user per month for 2 to 49 users. The pricing page still lists it under a banner that says Vibe Kanban is sunsetting. The shutdown post says subscriptions ended, so we cannot say whether you can buy it. [1] [9]
- Enterprise plan: no listed price, for 50 or more users. [9]
- Running it yourself costs your own computer or server, plus the subscriptions or API keys of the coding agents you use. It is not free in total.

Loupe:

- Self-hosting: the software costs nothing to license. You pay for your own server (web, worker and Postgres), for the subscriptions or API keys of Claude Code or Codex, and you need a GitHub.com repository. [L6]
- Hosted version: a private beta with a waitlist. Loupe publishes no price for it. [L6]

## Sources

Competitor sources were read on 2026-10-26.

1. [Vibe Kanban shutdown post, 2026-04-10](https://www.vibekanban.com/blog/shutdown)
2. [GitHub repository BloopAI/vibe-kanban](https://github.com/BloopAI/vibe-kanban) and its [GitHub API data](https://api.github.com/repos/BloopAI/vibe-kanban): 28,469 stars, Apache-2.0, not archived.
3. [Vibe Kanban README](https://github.com/BloopAI/vibe-kanban)
4. [Pull request 3387, "Sunset project routes to an export-only page"](https://github.com/BloopAI/vibe-kanban/pull/3387), merged 2026-04-24.
5. [Vibe Kanban docs: getting started](https://www.vibekanban.com/docs/getting-started)
6. [Recent commits](https://api.github.com/repos/BloopAI/vibe-kanban/commits?per_page=5) and [latest release](https://api.github.com/repos/BloopAI/vibe-kanban/releases/latest)
7. [npm registry entry for vibe-kanban](https://registry.npmjs.org/vibe-kanban/latest), version 0.1.44.
8. [Vibe Kanban issues](https://github.com/BloopAI/vibe-kanban/issues)
9. [Vibe Kanban pricing](https://www.vibekanban.com/pricing)

Loupe sources are in the Loupe repository, read on 2026-10-26.

- L1. Landing page, board feature text.
- L2. Landing page, workflow section. `docs/using/workflows.md`.
- L3. `docs/using/documents.md`.
- L4. `docs/using/inbox.md`.
- L5. `docs/using/analytics.md`.
- L6. Landing page, requirements and open-source sections. `docs/operating/install/index.md`. `docs/using/billing.md`.
