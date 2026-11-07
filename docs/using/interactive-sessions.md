---
title: "Interactive sessions"
description: "Work on a card with an agent in your own terminal: the product design and tech design sessions, working a card yourself, and how Loupe shows an open session."
---

An interactive session is work on a card that you do with an agent in your own
terminal. You see each step and answer each question. A worker that a
[bridge](../extending/cli-bridge.md) starts runs with nobody at the keyboard.
A session runs with you at the keyboard.

A session needs the `loupe-lifecycle` plugin in Claude Code. See
[The Claude Code plugins](mcp.md#the-claude-code-plugins) for the install
commands. The board must follow the Lifecycle [workflow](workflows.md)
template.

## The sessions

| Session | Start it with | What it does |
|---|---|---|
| Product design | `/loupe-lifecycle:product-design` | Interviews you and writes the product document of a card, or of a one-line idea |
| Tech design | `/loupe-lifecycle:tech-design` | Interviews you and writes the tech design of a card |
| Work a card | The `loupe-lifecycle-session` skill | Does the work of a card in your session and opens the pull request |

## Start a session

You can start a session in three ways.

- Type the command in Claude Code. Give it a card number, or a short idea for a
  product design.
- Let the workflow ask for it. A card in Product design with no product
  document asks for a product design session. A card in Tech design asks for a
  tech design session when its **Interactive session** switch is on. The
  request needs a bridge whose rule file has an entry with
  `action: interactive`. The bridge
  opens a terminal on its machine. See
  [Interactive action](../extending/cli-bridge.md#interactive-action).
- Select **Start an interactive session** on an item of the
  [inbox](inbox.md#start-an-interactive-session).

A session cannot open on a card that a worker holds. The agent stops and tells
you. You can wait for the worker, or stop it first.

## Product design

The session reads the card, its linked documents and the code before it asks
anything. Then it asks you questions about the problem, the scope and the
behaviour. When one of your installed skills explores ideas through questions,
the session can offer it as the interview method. See
[Interactive design sessions and installed skills](workflows.md#interactive-design-sessions-and-installed-skills).

The session also asks two questions that set a card switch:

- Whether a person must review the pull request of the card. The answer sets
  the **Requires human review** switch. See [Agent review](agent-review.md).
- Whether you run the tech design with the agent. The question appears only
  when the template of the card declares the **Interactive session** switch.

At the end, the session writes the product document and links it to the card.
It stops there. It does not move the card. Your approval of the document moves
the card to Tech design.

The session leaves the document as a draft. An agent reviews the draft first,
as [Agent review of a design](workflows.md#agent-review-of-a-design) says.
**Publish** on the draft skips that review.

## Tech design

The session needs a card. It reads the approved product document, the code and
the open blockers of the card. It then asks one question for each open
decision, and it shows the options with their costs. When the design needs
more than one pull request, it shows the proposed split and asks whether the
cut is right.

The session writes the tech design and links it to the card. It does not move
the card. Your approval of the design makes the move. A design that adds,
changes or removes a deploy item also updates the deploy notes.

## Work a card in a session

A session that does the work of a card itself follows the steps in
[Work a card in a session](workflows.md#work-a-card-in-a-session). In short,
the agent finds or creates the card, opens a run on it, does the work, opens a
draft pull request and links it to the card. When the template allows it, the
card moves to Implementation before the first edit, and no worker starts on it.
The workflow then reviews and merges the pull request.

## What the board shows

While a session is open on a card, the card shows the state **Working** with
the marker `session-open`. The workflow rules that ask for a design or a design
review wait while the session is open.

The session also shows as an interactive run on the **Runs** tab of the
[Activity](worker-runs.md#interactive-sessions) page. The run has no exit code
and no output, because no bridge holds it.

An open session ends in one of these ways:

- The agent closes it when its work ends.
- You select **Close session** on the running row.
- The card moves to another column.
- A person deletes the card.

A move inside the same column closes nothing.

## Related pages

- [Workflows](workflows.md) holds the rules that ask for sessions.
- [The command-line bridge](../extending/cli-bridge.md) explains how a bridge
  opens a terminal.
- [Agent review](agent-review.md) explains what happens to the pull request that
  a session opens.
