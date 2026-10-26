---
competitor: "Curia"
title: "Loupe vs Curia"
description: "Curia runs named Claude Code agents overnight on a Mac. Loupe gives agents a board, document review and an inbox. Compare reach and cost, read 2026-10-26."
comparedOn: 2026-10-26
competitorVersion: "no tagged release; last push 2026-10-10"
---

## In short

Curia is a single Python file that runs Claude Code agents as named seats with their own memory. It also runs offices that work a backlog overnight. Loupe is a web application with a board, workflow rules, document review and an owner inbox. Both aim at unattended agent work with a person in charge. Curia has deeper agent memory and overnight controls. Loupe has a web interface and fewer platform limits.

## What Curia does well

- A seat keeps a charter, handoff notes and mail across sessions. A hook asks the seat to write a handoff note before its context fills.
- Offices do routine work. Lictor writes reports, Censor checks integration branches, and Portcullis lands reviewed, green work.
- The launcher moves to the next Claude account when one runs out of headroom.
- The night office works a backlog unattended. It stops on a clear board, no progress, a deadline or a budget cap.
- Fences refuse forbidden actions, and Curia logs each refusal. The author says fences are polite refusals and not a security boundary.
- The engine needs only Python 3.11+, Claude Code and git. It has no other dependencies. The licence is MIT.

## What Loupe does well

- Loupe has a web interface for the board, the documents and the runs.
- A person reviews product and tech designs as Markdown documents with inline comments.
- The owner inbox collects agent questions and review requests, and can send a web push.
- Site review lets a person comment on a live web page.
- The bridge runs on macOS and Linux and supports Claude Code and Codex.
- Loupe does not need Beads or a Mac.

## Side by side

| Dimension | Loupe | Curia |
|---|---|---|
| Main idea | Board and workflow that move cards to a merged pull request | Named agent seats and overnight offices |
| Interface | Web application | Command line and files |
| Agents | Claude Code and Codex | Claude Code only |
| Platforms | Server on Linux through Docker; bridge on macOS and Linux | Its README mentions launchd units; the author states it needs a Mac |
| Persistent agent memory | No named seats | Yes, with handoff notes |
| Overnight runs | Rules start workers on card events | Night office with deadline and budget cap |
| Account rotation | Not checked for this page | Yes, across Claude accounts |
| Document review | Yes | No |
| Questions to a person | Owner inbox | Labels on tickets mark items that need a person |
| Licence | AGPL-3.0-or-later | MIT |
| Maturity | Young | Created 2026-08-31, 12 stars, no tagged release |

## Who should pick which

Pick Loupe if:

1. You work on Linux, or you want a web interface.
2. You want a person to review designs and answer agent questions in one place.
3. You use Codex as well as Claude Code.

Pick Curia if:

1. You use a Mac and Claude Code only.
2. You want named agents with memory that lasts across sessions.
3. You want overnight runs with a deadline or a budget cap, and you like a small tool that you can read in one file.

## Cost

Curia is free software under the MIT licence. Its cost is the agent usage that it starts.

- The author's launch post of 2026-09-29 states about $10,400 in tokens at list prices over 24 days. This was a single person on one client estate. That figure is the author's claim. This page could not read the post again on 2026-10-26, and the figure is not in the README.
- The author also gives a Mac, Python 3.11+, git and the `gh` tool as needs. Your own Claude accounts, plans or API keys pay for the tokens.
- Curia charges no fee. Its pages state no other price.

Loupe:

- Self-hosting is free under AGPL-3.0. You pay for the server that runs the web app, the worker and Postgres, and for the agent subscriptions or API usage of your workers. Loupe states no server size or monthly figure, and this page does not guess one.- Hosted Loupe is in private beta. The repository shows no public price, so no price is known.

## Sources

Curia facts were read on 2026-10-26 unless a line says otherwise.

1. Curia home page, https://curia.build
2. Curia repository and README, https://github.com/harrymunro/curia
3. Curia repository data, GitHub API, https://api.github.com/repos/harrymunro/curia
4. Curia launch post, https://www.reddit.com/r/ClaudeAI/comments/1wt8p97/introducing_curia/ , read on 2026-09-29. The $10,400 figure and the Mac, Claude Code only, Beads and one-month-old statements come from this post.
5. Loupe README, install guide, workflow, inbox and worker-run documentation, https://github.com/loupe-ac/loupe and https://loupe.ac/docs/

Nobody has run Curia for this page. The facts about it come from its own claims.
