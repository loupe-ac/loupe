---
competitor: "T3 Code"
title: "Loupe vs T3 Code"
description: "T3 Code is a control surface for coding agents. Loupe decides what work happens. Compare scope, platforms, licence and cost, read on 2026-10-26."
comparedOn: 2026-10-26
competitorVersion: "v0.1.2 (2026-10-22)"
---

## In short

T3 Code is a user interface for coding agents that run on your machine. One app drives Claude Code, Codex, Cursor, OpenCode, Grok Build and Google Antigravity. Loupe is a board and workflow that decides which work agents do, and a place where a person reviews it. The two tools solve different problems. A developer can run T3 Code with no board, and a team can run Loupe with no T3 Code.

## What T3 Code does well

- It supports six agent harnesses in one interface, and a thread can switch models.
- It runs as a desktop app on Windows, macOS and Linux, as a web app, and as iOS and Android apps. It also supports remote use from a phone.
- One button commits, pushes and opens a pull request with a generated title and body. It shows an inline diff first.
- You bring your own subscription. T3 Code does not resell tokens and sets no quota.
- Installation is one command, and packages exist for Homebrew, winget, scoop, .deb and the AUR.
- The licence is MIT. The repository has about 30.5k stars.

## What Loupe does well

- Loupe has a board with workflow rules. A card moves through product design, tech design, implementation, review and merge.
- A person reviews Markdown documents in Loupe with inline comments, and approves or requests changes.
- The owner inbox collects questions and review requests from agents.
- Site review lets a person comment on a live web page.
- Loupe shows each worker run on an Activity page.
- Loupe is meant for a project and its team. T3 Code describes itself as a control surface for the agents on one machine.

## Side by side

| Dimension | Loupe | T3 Code |
|---|---|---|
| Main idea | Decides what work happens and collects review | Controls agents that run on your machine |
| Licence | AGPL-3.0-or-later | MIT |
| Agent harnesses | Claude Code and Codex, through the bridge | Claude Code, Codex, Cursor, OpenCode, Grok Build, Antigravity |
| Interactive agent sessions | Bridge workers run without a person at the keyboard | Yes, with a thread per agent |
| Board and workflow | Yes | Not mentioned on its site or README |
| Document review | Yes | No |
| Mobile | Web app only | iOS and Android apps |
| Pull request button | Workflow rules write to GitHub | One button commits, pushes and opens a pull request |
| Install | Docker Compose, or the hosted private beta | One command, or a desktop package |
| Maturity | Young | Latest release is v0.1.2, and the site says "very very early" |

## Who should pick which

Pick Loupe if:

1. You want a board that starts agent work and tracks it to a merge.
2. You want a person to review designs and answer agent questions.
3. You work in a team.

Pick T3 Code if:

1. You want one interface for several coding agents on your own machine.
2. You want to use your agents from a phone.
3. You want a fast install and no server to run.

You can also use both. Each runs agents on your machine with your own subscriptions. T3 Code can drive an agent, and Loupe can decide the work. Neither project documents an integration, and this page does not claim that one exists.

## Cost

T3 Code:

- It has no paid tier. Its site says that it sells nothing.
- You pay for the agent subscriptions that you log in with. It sets no quota, so the limits are those of each provider.
- It runs on your own machine. The site gives no hosting cost for remote use, and a hosting cost for remote access is unknown.

Loupe:

- Self-hosting is free under AGPL-3.0. You pay for the server that runs the web app, the worker and Postgres, and for the agent subscriptions that your workers use. Loupe states no server size or monthly figure, and this page does not guess one.
- Hosted Loupe is in private beta. The repository shows no public price, so no price is known.

## Sources

Read on 2026-10-26.

1. T3 Code home page, https://t3.codes/
2. T3 Code repository and README, https://github.com/pingdotgg/t3code
3. T3 Code release v0.1.2, GitHub API, https://api.github.com/repos/pingdotgg/t3code/releases/latest
4. Loupe README, install guide, workflow, inbox and worker-run documentation, https://github.com/loupe-ac/loupe and https://loupe.ac/docs/

The page does not rest on a run of T3 Code. The facts about it come from its own site and README. The count of "over 500,000 devs" on its home page is its own claim and this page does not repeat it as fact.
