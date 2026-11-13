---
title: "First steps"
description: "Install the CLI, run loupe init, send a first document, review it, and find the next steps."
---

To run an instance of your own, read [Installing Loupe](../operating/install/index.md)
first.

This page is for a person who already has a Loupe account and the URL of an
instance. At the end, your agent sends a plan to Loupe, and you review it in the
browser. The examples use `https://loupe.example.com` as the instance URL.

## 1. Install the CLI

The `loupe` CLI connects a coding agent on your machine to Loupe. It runs on
macOS and Linux. Follow [Installing the CLI](cli.md), then check the install:

```bash
loupe version
```

## 2. Run loupe init

Run `loupe init` once in each repository:

```bash
cd ~/code/my-project
loupe init --url https://loupe.example.com
```

`loupe init` signs you in when you are signed out. The CLI prints a link and a
code. Open the link in a browser where you are signed in to Loupe. Make sure
that the page shows the same code, then choose **Allow**.
[Signing in the CLI with a code](../using/connected-apps.md#signing-in-the-cli-with-a-code)
tells what the page shows. Later runs need no `--url`.

Then it chooses the project. It lists the projects your login covers, even when
that is one project, and asks which one. The last row of the list creates a new
project. Pass `--name "<name>"` to create a project without the list. When your
login covers none, it creates one from the `origin` remote.
It runs `loupe project link` for that project, then installs the Claude Code
plugins, writes the rule file, and starts the bridge in the background.
[Run the bridge](run-the-bridge.md) describes these steps. A second run changes
nothing that is done already.

When the Loupe GitHub App is missing for your repository, `loupe init` offers to
install it. It opens the page in your browser, waits in the terminal, and goes on
when the repository joins the project. While `loupe init` runs, Loupe asks you
nothing in the inbox about the setup. The changes that `loupe init` proposes
arrive in one pull request, `Set up Loupe`. This needs CLI 2.7.0.

`loupe project link` writes `.loupe.yaml`, which holds the project id. Commit
the file, so everyone in the repository reaches the same project.

At the end, `loupe init` starts an agent that looks for blockers. A blocker
makes a worker fail on its first run, or does damage. The agent reads the
repository and the facts that Loupe knows of its GitHub rules. It shows one
summary with a table of what it found, and asks one question at a time, only
about a blocker, with the question tool of the agent when it has one. When the
Loupe GitHub App does not cover the repository, or Loupe cannot tell, the summary
opens with the install link. It
writes nothing to `.loupe/lifecycle.md`. It gets `.loupe.yaml`
to the remote in the way your repository usually works. A project whose workflow
needs no profile skips this step. With no terminal or no agent, `loupe init`
prints a prompt for you to paste into an agent. The agent runs when
`.loupe/lifecycle.md` is missing. Pass `--review-profile` to run it again.

The last lines name only what remains. They end with the link to the project
page. Start discovery from there. When the Loupe GitHub App does not cover the
repository of the folder, or Loupe cannot tell, a `Loupe GitHub App` block comes
first, with the install link. Installing the App is then the first item of what
remains.

When Claude Code does not start the `loupe` MCP server yet, `loupe project link`
offers to declare it for every repository. `--mcp` accepts the offer with no
question:

```bash
claude mcp add --scope user loupe -- loupe mcp
```

To commit the server to the repository instead, run
`loupe init --mcp-json`, which passes the flag on to `loupe project link`. It
writes `.mcp.json`:

```json
{
  "mcpServers": {
    "loupe": { "command": "loupe", "args": ["mcp"] }
  }
}
```

The file holds no credential. Claude Code asks you to approve the server the
first time. [Connecting through the CLI](../using/mcp.md#connecting-through-the-cli)
has the details.

The plugins that `loupe init` installs add skills that tell the agent how to
write a document, work a board and act on feedback. They hold no credential.
[The Claude Code plugins](../using/mcp.md#the-claude-code-plugins) lists the
skills, and shows how to install them by hand.

### The setup page

A new project is not set up until `loupe init` has finished and a bridge has
connected. Until then, each page of the project opens the **setup page**. It
shows the next step, such as the `loupe init --project <id>` command, and it
updates by itself while you work in the terminal. When both steps are done, it
shows **Open the project**.

Select **Skip for now** to open the project without the setup page for the rest
of the browser session. [The setup page](../using/projects.md#the-setup-page)
describes each state.

## 3. Open the readiness guide

Open the Workshop of the project in your instance. The project owner sees the
readiness guide there. It is a checklist of what the project needs before
agents can work on it, such as a connected agent, a ready bridge and the
agent account that pushes code. See
[The readiness guide](../using/workshop.md#the-readiness-guide).

## 4. Write a first document

Start Claude Code in the repository, and ask it for a plan. For example:

> Write an implementation plan for the next feature, and send it to Loupe for
> review.

The agent calls the `document_create` tool. Loupe stores the plan as a document
in your project, and the tool returns a review URL. The agent gives you that
URL.

## 5. Read a first review

Open the review URL. Select a passage to comment on it. A comment can also
suggest a replacement for the passage.

When you finish, select **Finish review**. Approve the version, or request
changes with a note. When you request changes, ask the agent to read the
review. It calls `document_get_review` and sends a new version. [Documents and review](../using/documents.md) describes the review page.

## 6. Act on the conflict findings

[Run discovery](../using/workshop.md#run-discovery) before agents work the cards
of this project. It compares the way the repository already works with what the
workflow needs, and it changes nothing except the routing table, which it writes
only after you agree. You get a readiness report.

Discovery also runs the deep checks that `loupe init` leaves out. They cover the
push gate, the pull request target, the conventions, rules that live only in
your user files, instruction lines that are false, tooling that breaks in a
worker folder, what the merge worker cannot read, who approves a merge, the merge
method, the skills of the repository, and the preview tooling. Discovery also
drafts the first `## Routing` table of `.loupe/lifecycle.md`, which says which
skill each stage loads. The draft is in the report. Discovery then asks you in
the inbox whether to write it. On a yes, discovery adds the table on a new branch
and gets it to the remote as your instruction files say.

Each finding that starts `Conflict check:` has one of these results:

- Ready means that the repository fits. The evidence says what the worker read.
- Could not be read means that the worker has no permission, or no way to read
  the setting. The worker did not guess it, so check the setting yourself. The
  Repositories tab of [Project settings](../using/projects.md#the-list) shows what
  Loupe read with the permissions of the GitHub App.
- A suggestion that names options means a conflict. Choose one of the options:
  change the repository, change `.loupe/lifecycle.md`, or change the workflow
  template.

A change that only you can make, such as a branch rule, has no proposed card. Make
it on the forge. Each other change is a proposed card. Tick the proposals that you
want, and approve the report. Loupe then creates one card in Next for each ticked
proposal.

## Next

- [Run the bridge](run-the-bridge.md) explains the rule file and how to start,
  check and stop the bridge.
- [The readiness guide](../using/workshop.md#the-readiness-guide) lists what the
  project still needs.
- [Workflows](../using/workflows.md) describe how a card moves across the board.
- [The agent GitHub account](agent-github-account.md) sets the git identity that
  your agents push as.
- [Run discovery](../using/workshop.md#run-discovery) has an agent read the
  repository and report on it.

## Other ways to connect

A client that is not on your machine connects by URL over OAuth, with no CLI.
Claude reaching your instance from Anthropic's servers is an example. See
[Connecting by URL](../using/mcp.md#connecting-by-url).

MCP and the CLI need a sign-in in a browser. A script or a CI job that calls the
public API can use an [API key](../using/api-keys.md) instead.
