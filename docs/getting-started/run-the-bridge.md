---
title: "Run the bridge"
description: "Start the loupe bridge with loupe init, run it in the background, check it with loupe status, and stop it."
---

The bridge is the `loupe bridge` process. It runs on your machine, watches your
project boards, and starts a coding agent for each piece of work that a card
needs. [Command-line bridge](../extending/cli-bridge.md) describes the protocol
between the bridge and Loupe.

## Set it up with loupe init

Install the CLI first. See [Installing the CLI](cli.md). Then run this in the
repository:

```bash
cd ~/code/my-project
loupe init
```

`loupe init` does each of these steps, and prints `done` or `skipped` for each:

1. It signs you in, when you are signed out.
2. It chooses the project, or creates one from the `origin` remote.
3. It runs `loupe project link`, which writes `.loupe.yaml` and connects the
   MCP server.
4. It installs the Loupe plugins that the project needs in Claude Code: `loupe`,
   and `loupe-lifecycle` when the workflow of the project uses it.
5. It writes the rule file.
6. It asks whether to turn on automatic updates.
7. It starts the bridge in the background.
8. It offers to install the Playwright browser, when the repository uses
   Playwright.
9. It offers to install the Loupe GitHub App when it is missing, opens the install
   page and waits until the repository is in the project. Press Enter to stop
   waiting.
10. It starts an agent that looks for blockers with you. A blocker makes a worker
   fail on its first run, or does damage. The agent writes nothing to
   `.loupe/lifecycle.md`. This step runs only when the workflow of the project
   needs a profile and the file is missing.
    The agent puts the changes you confirm in one pull request from the branch
    `loupe/init`, and asks whether to merge it.
11. It reports the state of each account, and prints what remains.

The account report comes before the agent starts. It names each failure with its
project, reason and fix. A failing account does not stop the agent, and
`loupe init` exits with an error when an account that a rule uses fails for this
project. A failure in another project does not change the exit status. The last
lines come after the agent exits. They name only the steps that remain, such as
fixing an account, pushing `.loupe.yaml` and the profile, restarting Claude Code
and starting discovery, and end with the link to the project page. The discovery
step goes away after a run starts, and it comes back when the latest run failed.

Each step skips itself when it is done already. A second run changes nothing,
so run `loupe init` again to repair the setup. On a rule file that exists, it
adds only the keys that are missing, and keeps your comments. When the bridge
runs already, `loupe init` reloads the rule file if it changed.

`loupe init --reset` writes the first-run rules again. It keeps the `projects`
map and the `autoUpdate` key. [`cli/README.md`](../../cli/README.md#loupe-init)
lists every flag.

## The rule file

A new rule file is short. This is an example for a Mac with Claude Code:

```yaml
appPrompts: true
accounts:
  claude-code:
    harness: claude-code
defaults:
  account: claude-code
  permissions: workspace
projects:
  my-project:
    dir: /Users/you/code/my-project
launch:
  command: ["open", "-a", "Terminal", "{script}"]
work:
  product-design:
    action: interactive
    prompt: /loupe-lifecycle:product-design {cardNumber}
  tech-design-session:
    action: interactive
    prompt: /loupe-lifecycle:tech-design {cardNumber}
```

- `appPrompts` lets the bridge run the prompt that ships with Loupe for a kind
  of work that the `work` map does not hold.
- `accounts` names the agent accounts. `loupe init` adds one for the agent it
  finds. This is `claude-code`, or `codex` when only `codex` is on your `PATH`.
- `defaults.permissions` is `workspace`.
- `projects` maps the project to the directory of the repository.
- `work.product-design` and `work.tech-design-session` are interactive entries.
  They open a terminal window, through `launch`, so you can talk to the agent.
  The second one runs when a card has its "Interactive session" switch on under
  Tech design. `loupe init` writes `launch` and these entries only when it
  knows a terminal. macOS always has one. On Linux,
  it looks for a common terminal program on your `PATH`.

On a machine with no known terminal, `loupe init` says that product design waits
for a person, and names the keys to add by hand.

By default, the bridge works each card in its own git worktree, in a folder
outside the repository. [Worker folders](../extending/cli-bridge.md#worker-folders)
describes where they live, and how to turn this off. It also gives the contract
for a worktree script of your own, such as one that your repository has already.

## Start and stop the bridge

`loupe init` starts the bridge for you. To start it yourself, run it in the
background:

```bash
loupe bridge run -d
```

The command returns when the bridge listens. It prints the process id and the
path of the log file. Without `-d`, the bridge stays in the foreground and
writes its log to the terminal.

To stop the bridge, run:

```bash
loupe bridge stop
```

The bridge ends its workers, sends their reports, and exits. The command fails
when no bridge reads the rule file.

After you edit the rule file, run `loupe bridge reload`. The bridge applies the
change with no restart.

## Check the setup

```bash
loupe status
```

`loupe status` checks your login, the project of the repository, and each agent
account of the rule file. The last line is `loupe status: PASS` or
`loupe status: FAIL`. When it fails, the error says what to run next.
