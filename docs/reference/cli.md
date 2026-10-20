---
title: "CLI commands"
description: "The commands of the loupe binary that set up a repository and run the bridge: init, project link, bridge run and stop, status, credential and agent-account."
---

The `loupe` binary runs on your machine. [Installing the CLI](../getting-started/cli.md)
says how to install it. [`cli/README.md`](../../cli/README.md) holds the full
detail of every command, and this page lists the ones you use most.

## `loupe init`

Sets Loupe up for the repository you stand in. Run it once after you install
the CLI, and run it again to repair the setup. Each step skips itself when it is
done, so a second run changes nothing.

The steps, in order:

1. Sign in, when you are not signed in.
2. Choose the project.
3. Link the repository, as `loupe project link` does.
4. Install the Loupe plugin in Claude Code, or declare `loupe mcp` to Codex.
5. Write the rule file.
6. Ask whether to turn on automatic updates.
7. Start the bridge in the background.
8. Install the Playwright browser, when the repository uses Playwright.
9. Print the page to open next.

| Flag | Purpose |
|---|---|
| `--project` | The project, by id or slug, instead of a choice from a list |
| `--url` | The Loupe instance to sign in to. Else `LOUPE_URL`, else `https://loupe.ac` |
| `--rules` | Read and write this rule file instead of the one in your config directory |
| `--reset` | Write the first-run rules again, and keep the `projects` map |
| `--force`, `--mcp`, `--no-mcp`, `--mcp-json` | Work as they do for `loupe project link` |

[`loupe init`](../../cli/README.md#loupe-init) gives each step in full.

## `loupe project link`

Writes `.loupe.yaml`, the file that names the Loupe project of a repository, and
checks how Claude Code starts the `loupe` MCP server. This command replaces the
old `loupe init`.

| Flag | Purpose |
|---|---|
| `--project` | The project id to write, instead of a choice from a list |
| `--force` | Replace an existing `.loupe.yaml` |
| `--mcp` | Declare `loupe mcp` to Claude Code without asking |
| `--no-mcp` | Leave the MCP server alone without asking |
| `--mcp-json` | Write `.mcp.json` instead, to commit the server to the repository |

## `loupe bridge run` and `loupe bridge stop`

`loupe bridge run` reads the rule file, claims the work requests that the
workflow opens, and runs an agent for each one. The
[bridge page](../extending/cli-bridge.md) describes the rule file.

- `loupe bridge run` stays in the foreground and logs to stdout and to the log file.
- `loupe bridge run -d` starts the bridge in the background and returns once the bridge listens. The log goes to `bridge.log` in the config directory, or to `--log-file`.
- `loupe bridge stop` stops the bridge that reads the rule file. The bridge ends its workers, sends their reports and exits. The command fails when no bridge runs.
- `loupe bridge reload` applies a changed rule file to a running bridge.

All three take `--rules` to name another rule file.

## `loupe status`

Checks the login, the project and the MCP connection with one real call. It
checks each agent account of the rule file too: the agent program is on the
`PATH`, the account is logged in, and each project sees the `loupe` MCP server
and the Loupe skills. A failing account that a rule uses fails the check.

It prints a note, and never fails, when:

- Claude Code does not start `loupe mcp`
- the rule file sets no permission default
- the rule file has no `appPrompts` and no `work` entry
- the rule file maps no project to the current directory

The last line on stdout is `loupe status: PASS` or `loupe status: FAIL`. Flags
are `--project` and `--rules`.

## `loupe review` (hidden helpers)

`loupe review` holds hidden helpers for the Live review panel: `status`, `sign-in`, `projects`, `targets`, `notes` and `save`. Each prints one JSON object on stdout. They are not a stable interface, so do not script against them.

`sign-in` starts a device sign-in, prints the code and the link, and exits. The panel then runs `status` until the sign-in ends. While nobody has approved the code, `status` prints `pending`. It finishes the sign-in with one token request at most per server interval. After an approval it saves the login and prints `ok`. After a denial or an expired code it prints `signedOut`.

A login that uses them needs a Loupe server release that lists the `site-review` scope for `loupe-cli`. An older server refuses the new scope at `loupe login`.

## `loupe credential`

Prints a GitHub token for the worker that calls it. A worker runs it through
git and `gh`, and you seldom run it yourself. With the argument `get` it speaks
the git credential helper protocol. It needs `LOUPE_RUN_ID` and
`LOUPE_CONTROL_SOCKET`, which the bridge sets for a worker. See
[Credentials for workers](../extending/cli-bridge.md#credentials-for-workers).

## `loupe agent-account`

Stores the token of a separate GitHub user, so that the workers of the bridge
push as that user.

| Command | Effect |
|---|---|
| `loupe agent-account set` | Reads a token from standard input, checks it with GitHub and stores it |
| `loupe agent-account show` | Prints the login and the id |
| `loupe agent-account clear` | Removes the account |

See [Agent account](../extending/cli-bridge.md#agent-account) for what a worker
gets.
