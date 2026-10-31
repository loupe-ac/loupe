---
title: Workshop
description: "See open requests and cards in motion."
---

Open a project to see its Workshop. It shows the requests and the cards in motion of that project.
A disabled inbox shows an explanation instead of an empty list.
The project owner can also see a [readiness guide](#the-readiness-guide), which can take the place of these sections.

Needs you shows the six oldest open inbox items, oldest first.
The list includes [automatic items](inbox.md#automatic-items), which Loupe opens for a waiting card.
The count includes all open items, including those outside this list.
Select **Open inbox** to see the full inbox.
Select a request to open its question, to-do, or review in the inbox.
The row shows the item kind and whether it blocks an agent.
When no request needs you, Needs you shows an empty state. When the inbox is disabled, it says so instead of claiming that no requests need attention.

In motion shows the cards that have an open [worker run](worker-runs.md): queued, resumed or running.
A worker run and an interactive session both count, and the card can be in any column.
A card with two open runs shows the most advanced one, so a running session comes before a queued worker.
Running cards come first, then resumed cards, then queued cards. Inside each group, the run that has waited or run the longest comes first.

Each tile shows the card number, column, and title.
It also shows the state of the run and the rule name, or **Interactive** for a session.
The elapsed time counts from the start of a running run, or from the arrival of a queued or resumed run.
The section updates without a page reload when a run or a card changes.

The section shows six tiles. When more cards are in motion, **+N more** opens the worker runs page with the **Open runs** filter.
A run whose card was deleted shows no tile and does not count.
When nothing is in motion, the section shows an empty state.
The run line of each tile sits at the bottom of the tile, so the tiles of one row line up.

Select a tile to open the card drawer without leaving Workshop.
Escape closes the drawer and returns focus to the tile.
If a card fails to load, Retry loads it again. Close card returns to Workshop.
Open board shows the full board, including the cards that are not in motion.

Your crew lists the connected bridges and links to the Agents page. With no bridge connected, its row links to the connection guide.
Workshop does not infer running agents from open cards or bridge heartbeats.
A bridge that missed its heartbeats reads "Stale", with the hint to run `loupe bridge status` on that machine.
It reads "Login expired", with the hint to run `loupe login` on that machine, when its login can no longer refresh.
The Agents page and the Connect page show the same state and the same hint.

## The readiness guide

The readiness guide shows the project owner what the project needs before agents can work on it.
Other members do not see it.
The guide is a checklist named Ready for agents. It counts the required rows that are done, for example "2 of 4 required steps done". It groups the rows in three sections.

Ready for discovery:

- Agent connected is done after an agent first calls the MCP server for this project. Connect opens the Connect page.
- Bridge ready is done when a bridge that serves the project sends heartbeats and can run the discovery work. With no running bridge, the row shows "Not running". Start opens the Connect page, which shows the `loupe init` command. `loupe init` starts the bridge. To start it again later, run `loupe bridge run -d`. See [Run the bridge](../getting-started/run-the-bridge.md). A bridge that is online but cannot run it shows "Online, but it cannot run this work", and Start opens the Connect page again. A bridge that is paused shows "Paused, so it takes no new work", and Resume opens the Agents page.
- Run discovery shows the latest [discovery](#run-discovery) of the project. It is done when the owner reviewed the discovery report.

Ready for pull requests:

- Install the GitHub App is done when a repository connects to the project through the GitHub App. Install starts the install when the instance has a GitHub App.
- Who pushes the code is optional, so the count leaves it out. Loupe manages the [git identity of agents](../getting-started/agent-github-account.md) by default, and the agents push as the Loupe GitHub App. The row is done when the owner records the login of an agent account, and a running bridge of the project pushes as that login. The row says which part is missing. Set up opens the agent account page.

Optional, which the count leaves out:

- Review widget is done when the project lists at least one site that may sign in to the widget. Set up opens the review widget panel of the Connect page. When the project has a website and no site yet, the field starts with the website. Loupe saves nothing until you select Save.

When Needs you is empty and nothing is in motion, the guide is the only content of the Workshop.
Its heading names the project, for example "Get Acme ready for agents".
The page has no Hide button. To hide the guide, use the [Agent readiness](projects.md#agent-readiness) tab of Project settings.
Open cards with no worker run, such as cards in Backlog or Next, do not change this layout.
The worker run of the discovery card does not change it either, so the guide stays in front while discovery runs.
The page reloads when a bridge reports a change that alters the rows, so a bridge that comes online turns its row to done without a reload by hand.

When an item needs you or a card is in motion, the Workshop shows its normal layout.
The checklist then sits at the top of the right column. Its × control hides the guide.

A hidden guide stays hidden. To show it again, use the [Agent readiness](projects.md#agent-readiness) tab of Project settings.
An agent can also show it again with the `readiness_guide_set` MCP tool.
When an instance upgrades, a project that has a card outside Backlog starts with the guide hidden.

## Run discovery

Discovery reads the repository and the workflow of the project, and writes a readiness report.
The worker only reads. It changes no file and opens no pull request.

To start it, select **Run discovery** in the Run discovery row.
Loupe creates a new tooling card in the Backlog, named "Discover what Acme needs for agents".
The card tracks the run, and Loupe moves it. Each run gets a new card.
The [`discovery` rule](workflows.md#rules-the-app-adds) then asks a bridge for the work.
An agent can start discovery with the `discovery_start` MCP tool, and read the checklist with `readiness_get`.

Discovery runs these generic checks on every project:

- How to build and test the project, and whether CI runs those commands.
- An instructions file for agents, such as `AGENTS.md` or `CLAUDE.md`.
- The GitHub remote, the GitHub App, the bridge and the agent GitHub account.
- Playwright is installed, and an agent can use it.
- Each setup step that an agent cannot do through the MCP.

It also runs the checks of each kind of work that the workflow asks for.
The [Lifecycle workflow](workflows.md) asks for product design work, so it adds two checks for a project with a user interface.
The first check looks for a design system that an agent can read.
The second check looks for a Design System artifact on claude.ai that holds or mirrors it, named in `.loupe/lifecycle.md` or the agent instructions.
A project with no user interface, such as a library or a CLI, gets no finding for these two checks.
A worker that runs on a bridge cannot open claude.ai artifacts.
It trusts the repository, and the finding says that it did not check the artifact.
A project with a design system in the repository and no artifact gets a suggestion.
Only you can create the artifact, in a browser on claude.ai.
A check that the project does not meet is a suggestion, never a failure.
A suggestion becomes a proposed card that leaves the method open.

Discovery needs a running bridge that serves the project.
The project must also have a workflow, and **Run the workflow of the board** must be on, on the [Automation](board.md#automation) tab.
Otherwise the workflow cannot ask a bridge for the work, so Loupe refuses the start.
The bridge must set `appPrompts: true` in its `rules.yaml`, and its CLI must support app prompts.
See [Command-line bridge](../extending/cli-bridge.md#work-requests).

The Run discovery row shows the state of the latest run:

| Status | What it means | Action |
|---|---|---|
| Not run | Discovery never ran on this project | Run discovery |
| Discovery waits for a running bridge | Discovery never ran, and no bridge serves the project now | None. The Bridge ready row links to the setup |
| Discovery waits for a bridge that can run it | Discovery never ran, and a bridge is online but cannot run the discovery work | None. Map the `discovery` kind in the bridge rule file, or set `appPrompts: true`. Run `loupe init` again when the bridge is old |
| Discovery waits for a bridge that is not paused | Discovery never ran, and every bridge that can run it is paused | None. Resume new work on a bridge on the Agents page |
| Discovery waits for a workflow | Discovery never ran, and the project has no workflow | None. Choose a workflow for the project |
| Discovery waits for board automation | Discovery never ran, and the workflow of the board is off | None. Switch on **Run the workflow of the board** |
| Running on card #N | A run is open. A second start is refused until it ends | Open card |
| Discovery failed: reason | The run ended with no report. "No bridge took the work." means that no online bridge serves the project, so the request failed at once. "A bridge is online, but none can run discovery." means that the request failed at once, because no online bridge could take it. "The worker ended without a report." means that the worker stopped before it reported. "The discovery card left Backlog before the work ended." means that the work stopped because the card moved | Run again, when discovery can start |
| A worker revises the report on card #N | You asked for changes to the report. The run is in the Revising state | Open report |
| The report waits for your review | The worker reported. The run is in the Reported state | Open report |
| Reviewed | The owner approved the report. The run is in the Done state, and the row is done | None |

## Review the report

The worker submits its report with the `readiness_report_submit` MCP tool.
Loupe writes the report as a document with the tag `readiness-report`, and links it to the discovery card.
The document lists the checks that are ready under **Ready**, the other checks under **Suggested**, and one section for each card the worker proposes.

A proposal that has no open card gets a tick box in a **Decision** block at the end of the report.
A proposal that names an open card shows "Card N covers this" and has no tick box.
Tick the proposals you want, and approve the report.

When you approve the report, Loupe creates one card for each ticked proposal.
The cards go in the column of the proposal slot that the app rules name, which Loupe ships as `next`.
When the project has no such column, they go in the Backlog.
The run is then Done.
When the discovery card is in the Backlog, the workflow moves it to the first done column.
A discovery card in another column stays there.
Proposals you leave unticked create no card.

A second approval of the same report creates no second card.

A verdict of changes requested creates no card. The run moves to Revising, and the `discovery-revise` rule asks a worker to revise the same report.
The worker submits a new version of the report document. Loupe replaces the proposals that made no card, and the run goes back to Reported.
Review the new version, and approve it or ask for changes again.

When you approve the report while a worker revises it, the approval wins.
Loupe stops the revision, and creates the cards from the version you approved.
While the run is Requested or Revising, the discovery card takes no manual move.
