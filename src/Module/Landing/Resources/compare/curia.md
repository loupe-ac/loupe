---
competitor: "Curia"
title: "Loupe vs Curia"
description: "Curia runs named Claude Code agents overnight. Loupe gives agents a board, document review and an inbox. Compare reach and cost, read 2026-10-26."
comparedOn: 2026-10-26
competitorVersion: "no tagged release; last push 2026-10-10"
---

## In short

Curia is a single Python file that runs Claude Code agents as named seats with their own memory. It also runs offices that work a backlog overnight. Loupe is a web application with a board, workflow rules, document review and an owner inbox. Both aim at unattended agent work with a person in charge. Curia has deeper agent memory and overnight controls. Loupe has a web interface and supports more than one agent.

## What Curia does well

- A seat keeps a charter, handoff notes and mail across sessions. A hook asks the seat to write a handoff note before its context fills. [2]
- Offices do routine work. Lictor writes reports, Censor checks integration branches, and Portcullis lands reviewed, green work. [2]
- The launcher moves to the next Claude account when one runs out of headroom. [2]
- The night office works a backlog unattended. It stops on a clear board, no progress, a deadline or a budget cap. [2]
- Fences refuse forbidden actions, and Curia logs each refusal. [2]
- The engine needs only Python 3.11+, Claude Code and git. It has no other dependencies. The licence is MIT. [2] [3]

## What Loupe does well

- Loupe has a web interface for the board, the documents and the runs. [4]
- A person reviews product and tech designs as Markdown documents with inline comments. [4]
- The owner inbox collects agent questions and review requests, and can send a web push. [4]
- Site review lets a person comment on a live web page. [4]
- The bridge runs on macOS and Linux and supports Claude Code and Codex. [4]
- Loupe keeps cards on its own board, with no outside issue tracker. [4]

## Side by side

The Curia column comes from its home page, README and repository data. [1] [2] [3] The Loupe column comes from the Loupe documentation. [4]

| Dimension | Loupe | Curia |
|---|---|---|
| Main idea | Board and workflow that move cards to a merged pull request | Named agent seats and overnight offices |
| Interface | Web application | Command line and files |
| Agents | Claude Code and Codex | Claude Code; the README names no other agent |
| Platforms | Server on Linux through Docker; bridge on macOS and Linux | Its README mentions launchd units, a macOS service format, and lists no supported platforms |
| Persistent agent memory | No named seats | Yes, with handoff notes |
| Overnight runs | Rules start workers on card events | Night office with deadline and budget cap |
| Account rotation | Not checked for this page | Yes, across Claude accounts |
| Document review | Yes | No |
| Questions to a person | Owner inbox | Labels on tickets mark items that need a person |
| Licence | AGPL-3.0-or-later | MIT |
| Maturity | Young | Created 2026-08-31, 12 stars, no tagged release |

## Who should pick which

Pick Loupe if:

1. You want a web interface.
2. You want a person to review designs and answer agent questions in one place.
3. You use Codex as well as Claude Code.

Pick Curia if:

1. You use Claude Code only.
2. You want named agents with memory that lasts across sessions.
3. You want overnight runs with a deadline or a budget cap, and you like a small tool that you can read in one file.

## Cost

Curia is free software under the MIT licence. [2] Its cost is the agent usage that it starts.

- The README shows a spend cap on a run, such as `--budget 200` for $200 of recorded spend. Curia states no typical cost. [2]
- Curia needs Python 3.11+, Claude Code and git. Your own Claude accounts pay for the tokens. [2]
- Curia charges no fee. Its pages state no other price. [1] [2]

Loupe:

- Self-hosting is free under AGPL-3.0. You pay for the server that runs the web app, the worker and Postgres, and for the agent subscriptions or API usage of your workers. Loupe states no server size or monthly figure, and this page does not guess one.
- Hosted Loupe is in private beta. The repository shows no public price, so no price is known.

## Sources

Curia facts were read on 2026-10-26 unless a line says otherwise.

1. [Curia home page](https://curia.build)
2. [Curia repository and README](https://github.com/harrymunro/curia)
3. [Curia repository data, from the GitHub API](https://api.github.com/repos/harrymunro/curia)
4. [Loupe repository and README](https://github.com/loupe-ac/loupe), and the [Loupe documentation](https://loupe.ac/docs/) on install, workflows, the inbox and worker runs

Nobody has run Curia for this page. The facts about it come from its own claims.
