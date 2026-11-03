---
title: "CLI commands"
description: "The commands of the loupe binary: setups, init, project link, the commands that read and change projects, cards, documents, feedback and artifacts through the public API, bridge run and stop, status, credential and agent-account."
---

The `loupe` binary runs on your machine. [Installing the CLI](../getting-started/cli.md)
says how to install it. [`cli/README.md`](../../cli/README.md) holds the full
detail of every command, and this page lists the ones you use most.

## Setups: `-c` and `LOUPE_CONFIG`

A setup is the folder that holds one login and everything that depends on it.
By default it is the `loupe` folder in your OS config directory. A second setup
lets you sign in to another Loupe instance, such as a preview, and keep your
real login and bridge as they are.

Every command takes the persistent flag `-c <folder>` or `--config <folder>`. The
variable `LOUPE_CONFIG` names a folder in the same way. The flag wins when you set
both. A relative path and a leading `~` become an absolute path.

```bash
loupe -c ~/loupe-preview login
loupe -c ~/loupe-preview init --project <uuid>
LOUPE_CONFIG=~/loupe-preview loupe status
```

The setup folder holds these files and folders:

- `config.json`, the login
- `rules.yaml`, the rule file
- `bridge.log`, the log of the bridge, and its dated files such as `bridge.2026-10-29.log`
- `bridge-<hash>.state.json`, one file for each bridge, which names its log
- `runs` and `worktrees`, the files of the workers
- `projects.json`, the project links of the setup
- `tmp`, the temporary files of the setup, with mode 0700

The CLI writes `LOUPE_CONFIG` into its own environment. The bridge, its workers,
`loupe mcp` and the Live review panel inherit it, so they use the same setup.
A command with neither the flag nor the variable uses the default setup, and
nothing changes for it.

A setup other than the default writes nothing outside its folder. It keeps its
temporary files in `tmp`, and it leaves the keychain alone.

A bridge listens on a Unix socket in the setup folder, at
`bridge-<hash>.sock`. The path must be shorter than 104 bytes, the limit on
macOS. The folder path can have about 78 characters. A longer path makes the bridge
refuse to start with the message `use a shorter setup folder than <folder>`.

A second bridge needs its own clone of the repository. Two bridges that read one
clone can name the same `card-<n>-...` branch, and Git refuses to check out one
branch in two worktrees.

### Project links in a setup

`loupe init` and `loupe project link` in a second setup write the link into
`projects.json` in the setup folder. They never read or write `.loupe.yaml`.
A link covers the whole Git repository. The key is the absolute path of
`git rev-parse --git-common-dir`, so every subfolder and every worktree of one
checkout finds the link. A folder outside Git is its own key.
`loupe mcp`, `loupe status` and `loupe artifact` read the link first, and use
`.loupe.yaml` only when the setup links no project. A `projects.json` that
fails to read stops them at start. When it breaks later, they keep the last
project of the link.

`loupe mcp`, `loupe status` and `loupe artifact` read the link of the setup first.
They use `.loupe.yaml` when the setup has no link. `loupe init` and
`loupe project link` do not use `.loupe.yaml` at all.

In a second setup, `loupe init` skips every step that writes a shared file. It
prints `skipped, a second setup leaves shared files alone` for the plugin, the
Codex and the Playwright steps. It writes no `.loupe/lifecycle.md`, no
`.mcp.json` and no Claude Code declaration. The rule file goes to `rules.yaml` in
the setup folder, and the bridge that starts is the bridge of the setup.

When the setup links the folder to a project, and you pass another `--project`,
the command stops:

```text
the setup <folder> links this folder to <project>: pass --force to replace the link, or leave out --project
```

`--force` replaces the link. `loupe project link` stops in the same way, with
`the setup <folder> links this folder to project <id> already: pass --force to replace the link`.

### Built setups

A setup is built when it holds `bin/loupe` or `build.json`. People who work on
Loupe build one with `just cli-setup <folder>`;
[`cli/README.md`](../../cli/README.md#setups-and-builds) gives the recipe.

A run in a built setup uses only the build that the recipe checked. Before it
handles any flag, `--help` and `--version` included, the CLI compares the size and
the SHA-256 of `bin/loupe` with `build.json`. It also checks that you own the
file and that no other user can write it. The CLI hands the run to
`bin/loupe` when another file runs. When a check fails, the command stops:

```text
<folder>/bin/loupe is not the build that just cli-setup checked; run just cli-setup <folder>
```

A setup that holds `build.json` and no `bin/loupe` stops too. It never falls back to the
shared binary.

The bridge and its workers run a pinned copy of the checked build, in
`tmp/pin/<sha256>/` in the setup folder. A rebuild reaches a running bridge only after you
stop it and start it again. A pin is hashed before reuse and before it runs. A changed pin is
copied again, and a run from a changed pin hands over to `bin/loupe`. A bridge start removes the pins of other builds that no run used
for seven days.

### Updates in a setup

A setup other than the default never replaces a binary.

- In a built setup, `loupe -c <folder> update` stops. The message names the
  checkout and the commit, and says to run `just cli-setup <folder>`. The bridge
  of the setup runs no update check and reports a build.
- In a setup that runs the shared binary, `loupe -c <folder> update` installs only the
  panel copy of the running version and the newest skill packages into the setup. It prints that the binary
  updates from the default setup. It asks no running bridge, and it runs for a
  Homebrew binary too. The bridge runs no CLI update check and reports that updates
  are off. With `autoUpdate: true`, it still updates the skill packages of the setup
  every hour. A release of the shared binary outside the server range
  [drains](../extending/cli-bridge.md#draining). To replace that binary, run
  `LOUPE_CONFIG= loupe update`, then start the bridge again.

## `loupe init`

Sets Loupe up for the repository you stand in. Run it once after you install
the CLI, and run it again to repair the setup. Each step skips itself when it is
done, so a second run changes nothing.

The steps, in order:

1. Sign in, when you are not signed in.
2. Choose the project.
3. Link the repository, as `loupe project link` does.
4. Install the Loupe plugins that the project needs in Claude Code, or declare `loupe mcp` to Codex.
5. Write the rule file.
6. Ask whether to turn on automatic updates.
7. Start the bridge in the background.
8. Install the Playwright browser, when the repository uses Playwright.
9. Write the repository profile with an agent, when the workflow of the project needs one and the file is missing or unfinished.
10. Report the accounts, and print what remains.

Before step 9, `loupe init` prints each account of the rule file as ready, or prints each failure with its project, reason and fix. A failing account does not stop the agent. The last lines come after the agent exits, and they name only the steps that remain, such as fixing an account, finishing the profile, pushing the two files, restarting Claude Code and starting discovery. The discovery step goes away after a run starts, and it comes back when the latest run failed. They end with the link to the project page. `loupe init` exits with an error when an account that a rule uses fails for this project. A failure in another project does not change the exit status.

| Flag | Purpose |
|---|---|
| `--project` | The project, by id or slug, instead of a choice from a list |
| `--name` | Create a project with this name and link the repository to it. It cannot go with `--project`. A linked project needs `--force` |
| `--domain` | The domain of the new project. It needs `--name` |
| `--origin` | An allowed origin of the new project for the site-review widget. Repeat it for more. It needs `--name` |
| `--url` | The Loupe instance to sign in to. Else `LOUPE_URL`, else `https://app.loupe.ac` |
| `--rules` | Read and write this rule file instead of the one in your config directory |
| `--reset` | Write the first-run rules again, and keep the `projects` map |
| `--agent` | The agent that writes the profile: `claude` or `codex`. With both installed and a terminal, `loupe init` asks once |
| `--review-profile` | Start the profile agent also when `.loupe/lifecycle.md` is complete, to review each value |
| `--force`, `--mcp`, `--no-mcp`, `--mcp-json` | Work as they do for `loupe project link` |

The project list always shows when your login covers a project, also when it covers one. Its last row creates a new project. With no terminal, `loupe init` prints the list and stops. Pass `--project <slug>` to use a project, or `--name "<name>"` to create one.

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

## `loupe project list`

Lists the projects that your login covers. Each row gives the id, the slug and
the name of one project. Give the id to `loupe init --project <id>` or to
`loupe project link --project <id>`.

```text
  ID                                    SLUG  NAME
* 01a0c0d9-905c-7922-a586-ccc8ce043704  dev   Dev Project
  01a0c0e1-1111-7922-a586-ccc8ce043705  -     Beta

* marks the project that .loupe.yaml names.
```

A star marks the project of the current directory on a terminal. In a setup other than the
default, the link of the setup names that project first, then `.loupe.yaml`. A
dash shows a project with no slug. When your login covers no project, the
command says so and exits with status 0.

The command reads the public API, so it takes the shared flags of
[the public API commands](#commands-for-the-public-api): `--format`, `--fields`,
`--limit`, `--cursor`, `--sort` and `--retry`. In a pipe, tsv holds no star.
`LOUPE_API_KEY` works here too. The next section covers the other
`loupe project` commands, `get`, `create` and `update`.

## Commands for the public API

The commands below call the [public API](api/index.md). Each one matches one
operation, and a test in CI fails when an operation has no command. A person
types them at a terminal, and a script or an agent runs them with an API key.

These commands are in beta, as the public API is. A command, a flag, a field
set or an exit code can change in a minor release until the beta ends. The
changelog names each change. `--help` on each group says "beta". After the
beta, no rename or removal comes without a new major version of the CLI.

The commands need a CLI release that holds them, and a Loupe server that has
the public API, the `fields` parameter and the `url` field. Run
`loupe card get --help` to see whether your CLI has them. A plugin and the CLI
update separately, so update both.

### Sign in and instance

A public command reads its credential in this order:

1. `LOUPE_API_KEY`, an [API key](../using/api-keys.md). The CLI never stores it.
   `LOUPE_URL` names the instance, else `https://app.loupe.ac`. A command with
   a key touches no config file.
2. The login of `loupe login`. A login from before the public API existed needs
   a new `loupe login`.

`loupe bridge`, `loupe mcp` and the other bridge commands ignore
`LOUPE_API_KEY` and use the login, because a key cannot reach a bridge route.

### Project

A command that needs a project finds it in this order:

1. `--project`, by id or slug
2. `LOUPE_PROJECT`
3. The project link of the setup, then `.loupe.yaml`
4. The one project that the credential reaches

When several projects are possible and none is named, the command exits 2 and
names the ways to choose. It never guesses.

### Groups

| Command | Operations |
|---|---|
| `loupe project` | `list`, `get`, `create`, `update`, and `link` from above |
| `loupe column` | `list`, `get`, `create`, `update`, `delete`, `reorder` |
| `loupe card` | `list`, `get`, `history`, `create`, `update`, `hold`, `release`, `pause-release` |
| `loupe feedback` | `list`, `get`, `address` |
| `loupe document` | `list`, `get`, `review`, `create`, `update`, `publish`, `revise`, `verdict`, `verdict-withdraw`, `decision-answer`, `decision-clear`, `reply`, `address`, and `version list` and `version get` |
| `loupe artifact` | `list`, `get`, `update`, `create`, `version create`, `read` |

`loupe card list` filters by the state of a card with `--state` and
`--state-code`: `loupe card list --state working` lists the cards with the
working mark, and `--state-code run-open` narrows them to the running workers.
`--state` takes `stuck`, `needs-you`, `working` or `waiting`. A card in a
terminal column has no state. The flags need a CLI release after `cli/v2.0.1`
and a server that has the filters. A server without them ignores the flags and
answers every card.

The verbs are the words of the MCP tools, so `document_revise` is
`loupe document revise`. A flag is the API parameter in kebab-case:
`includeArchived` is `--include-archived`. `loupe <group> <command> --help`
lists them. The OpenAPI file and the list of API versions have no command,
because they need no sign-in. Use curl for them.

### Output

| Flag | Purpose |
|---|---|
| `--format` | `table`, `tsv`, `json` or `toon`. TOON is a compact text format for agents. A terminal gets a table, and a pipe gets tsv with no header. |
| `--fields` | The fields to print, as a comma list. `all` prints every field, so a list in a table or tsv gets one column for each field that its rows hold. On a read, the CLI sends the API parameter `fields`. |
| `--project` | The project, as above |
| `--retry` | Wait for `Retry-After` on a 429 answer and send the request once more. It waits at most 120 seconds. |

1. **A list returns one page.** `--limit` and `--cursor` work as the API
   parameters do, and `--cursor` takes a cursor or the whole `next` address.
   json and toon print `items` and `next`. A table and tsv write the next
   cursor to stderr. A list never walks pages by itself.
2. **An empty list succeeds.** It exits 0 and prints nothing in tsv.
3. **tsv.** A list prints one row per item, with the columns of `--fields` in
   the order you named them. Without `--fields`, it prints the default columns
   of the command. After the beta, a change to a tsv column counts as a
   breaking change. A tab, a newline and a backslash in a value are written as
   `\t`, `\n` and `\\`. A null is empty.
4. **One item.** It prints as `name` and value lines. Plain fields come first and
   long text such as `body` comes last. In tsv, one named field prints the bare
   value, so `loupe card get 42 --fields url` prints only the link.
5. **A table.** It shows short times such as "2h ago" and cuts wide values.
   json, toon and tsv hold the exact values.

### Writes

1. **Values.** Each field has a flag. `--body-file` reads long text, with `-` for
   stdin. `--input` reads a whole JSON body from a file or stdin. When both name
   a field, the flag wins. A list field such as `relatedCards` is replaced whole.
   The word `null` sets a nullable field to null. A real value of `null` in such
   a field needs `--input`.
2. **Output.** A write prints a note on stderr and the app link of the item on
   stdout. `--format` replaces the link with the answer. `--fields` picks fields
   of the answer. A name that the answer does not hold exits 2 before the CLI
   sends the write. A write on an item with no page, such as a column, prints no
   link.
3. **Retry.** `--idempotency-key <key>` sends that key on a create. When
   `LOUPE_IDEMPOTENCY_SEED` is set, the CLI derives a key for each create from
   the seed, the command, the project and the body. A rerun then repeats each
   answer, and two different creates stay different. The flag wins. With
   neither, the CLI sends no key.
4. **A missing value.** The command never prompts for it. It exits 2 and names
   the flag.
5. **`column delete`.** It is the only command that asks. A terminal gets a
   prompt, and `--yes` skips it. With no terminal and no `--yes`, it exits 2
   and changes nothing. A column that holds cards also needs
   `--target-column`. `--yes` never chooses a target. Without one, the API
   answers `column_target_required`, and the command exits 6.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Any other failure, such as a 5xx, no network, or a 404 that is not an answer of the API |
| `2` | Bad usage, such as an unknown flag or a missing value |
| `4` | Sign-in: a 401, or a 403 with `credential_not_allowed` |
| `5` | Not found: a 404 from the API |
| `6` | Refused: any other 4xx, such as 409, 422 and 429 |

stdout stays empty on a failure. stderr starts with the code of the API, as in
`error: card_not_found: This project has no card with this number.` With
`--format json`, stderr holds the Problem JSON that the API sent. A 429 exits 6
at once and names the wait, unless you pass `--retry`.

### Environment variables

| Variable | Purpose |
|---|---|
| `LOUPE_API_KEY` | The API key of a public command. The CLI never stores it. |
| `LOUPE_URL` | The Loupe instance, else `https://app.loupe.ac` |
| `LOUPE_PROJECT` | The project of a command that has no `--project` |
| `LOUPE_IDEMPOTENCY_SEED` | Turns on a derived idempotency key for each create |
| `LOUPE_CONFIG` | The setup folder, as above |

### Artifacts

`loupe artifact create <path> --name <name>` stores a new artifact, and
`loupe artifact version create <artifact> <path>` adds a version. They replace
`loupe artifact push`, which stays as a hidden command that exits 2 and names
them. Any credential can store, a worker run included, and the commands send no
session id. [Storing from the command line](../using/artifacts.md#storing-from-the-command-line)
has the flags.

`loupe artifact read <artifact> <path>` gives the exact bytes of one file. Use
`--version-number` for an older version.

1. **Default.** stdout holds the bytes and nothing else, so
   `loupe artifact read <id> logo.png > logo.png` gives a correct file.
2. **A terminal.** A text file prints. A file that is not text exits 2, prints
   nothing and names `--output`. A file is text when it is valid UTF-8 and holds
   no control character except tab, newline and carriage return.
3. **`--output <file>`.** It writes the bytes to that file and leaves stdout
   empty. It exits 2 when the file exists, unless you pass `--clobber`. A failed
   read creates no file and changes no existing file. `--output -` writes to
   stdout, also on a terminal.
4. **No `--format` and no `--fields`.** The answer is a file, so each flag exits 2.
   `loupe artifact get` lists the files with their content type and size.

The shell empties the target of `>` before the command starts. A failed read
with `> logo.png` therefore leaves an empty file. Use `--output <file>
--clobber` to keep an existing file on a failure.

## `loupe bridge run` and `loupe bridge stop`

`loupe bridge run` reads the rule file, claims the work requests that the
workflow opens, and runs an agent for each one. The
[bridge page](../extending/cli-bridge.md) describes the rule file.

- `loupe bridge run` stays in the foreground and logs to stdout and to the log file.
- `loupe bridge run -d` starts the bridge in the background and returns once the bridge listens. The log goes to `bridge.log` in the config directory, or to `--log-file`.
- `loupe bridge stop` stops the bridge that reads the rule file. The bridge ends its workers, sends their reports and exits. The command fails when no bridge runs.
- `loupe bridge reload` applies a changed rule file to a running bridge.
- `loupe bridge status` shows the running bridges. See [`loupe bridge status`](#loupe-bridge-status).

`run`, `stop` and `reload` take `--rules` to name another rule file.

## `loupe bridge status`

Shows every bridge that runs on this machine. The command asks each bridge over
its control socket and makes no network call. It prints one block for each
bridge, headed by the name of the bridge. A block shows:

- the rule file, the process id, the CLI version and the log file
- the login, which is `valid` or `expired, waiting for loupe login`
- the time of the last heartbeat that the server accepted
- the last warning or error of the log, with its time
- each running worker, with its card, its kind and its start time

A bridge on an older CLI cannot report its status. Its block says that the
bridge runs on an older CLI and tells you to run `loupe update`.

`--bridge <name>` shows the bridge with that name. When two running bridges
have the same name, the command stops and asks you to set `name:` in one rule
file. When no running bridge has the name, the command fails.

The command exits with status 0 when every bridge shown runs with a valid login.
It exits with status 1 when no bridge runs, or when a bridge shown waits for a
new login. With `--bridge`, the status covers that bridge only.

## `loupe bridge logs`

`loupe bridge logs` prints the last entries of the bridge log. It reads files on this
machine, so it needs no network and no running bridge. It finds the log of each bridge
that ran here, also a stopped one, because every bridge leaves a state file that names
its log. It reads the dated files of a rotated log too, and merges the entries of all
bridges by time.

- `-n <count>` prints the last `count` entries. The default is 50.
- `-f` keeps reading and prints new entries until you stop it. It follows the log across the daily rotation.
- `--bridge <name>` keeps the last entries of one bridge. An entry that an older CLI wrote has no name, so it shows only without this flag. When two running bridges share the name, the command stops and asks you to set `name:` in one rule file.
- `--json` prints each entry as the bridge wrote it. Without it, the output is logfmt: the `time`, the `level` and the `event`, then the other keys of the entry.
- `--log-file <path>` reads that log in place of the logs of the bridges on this machine.

When it finds no log, the command names each path it checked.

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

It starts with two lines. `Setup:` gives the setup folder and what chose it, which
is `-c`, `LOUPE_CONFIG` or `the default`. `Binary:` gives the release version,
or `a build at <commit>` for a development build. In a built setup it reads
`a build of <checkout> at <commit>`.

The `Bridges:` line counts the running bridges and gives their login state, as in
`1 running, login valid.` or `none running.`. It reads the bridges with no
network call. No running bridge is reported and does not fail the check. A bridge
with an expired login fails the check, and the line points to `loupe bridge status`.

The `Skills:` lines give each skill package in the store with its highest usable
version and its state. A usable version is one that the CLI can run and whose
`requires` the store can meet. A running bridge from another setup can hold another
version, and that version gets its own line that names the bridge. When the last
update of a package failed on a running bridge, the line gives the version of that
bridge and the reason and names the bridge, with one line for each such bridge. They
are notes, and they never fail the check.

The last line on stdout is `loupe status: PASS` or `loupe status: FAIL`. Flags
are `--project` and `--rules`.

## `loupe session end`

Closes the open interactive runs of a Claude Code session that just ended. The
`SessionEnd` hook of the Loupe plugin starts it in the background. The hook
writes its JSON to stdin, and the command reads the `session_id` from it.

The command uses your stored login. It reads no project file and no setup link,
because one session can work in several projects. Loupe closes the runs of the
session in every project of your account, with the reason `session-ended`. The
command prints nothing on success. On failure it exits with 1 and prints the
reason on stderr, and the hook ignores that. A run that the hook did not close
closes later, as [stale sessions](../using/worker-runs.md#stale-sessions) says.

The hook does nothing on a machine where `loupe` is not on the `PATH`.

## `loupe session remind`

Reminds a Claude Code session to open a card run before it edits files. The `PreToolUse` hook of the Loupe plugin runs it before an `Edit` or `Write` call, and before a `gh pr create` command. The hook writes its JSON to stdin, and the command reads the `session_id`, the tool name and the command from it.

The command prints a reminder when all of these are true:

- `.loupe.yaml` in the working directory names a project, or the project link of the setup does. The link of a setup other than the default wins. A project that only the server picks as your single project does not count.
- The project uses the Lifecycle workflow template.
- The session has no open card run in that project.

The reminder tells the session to find or create the card and call `card_run_open` with the Implementation status. It tells the session to ignore the reminder when the work is not card work.

The command never blocks a tool call. It prints nothing and exits with 0 on every failure, such as no login, no network, an answer that takes more than 2 seconds, or an unknown answer. It prints nothing for a bridge worker, because a worker has a run already.

The command keeps a small state file for each session in the `session-remind` folder of the config folder, with mode 0600. A project with another template silences the session for good. A found open run, or a reminder just given, silences the session for 10 minutes. A `gh pr create` command always checks again.

The hook does nothing on a machine where `loupe` is not on the `PATH`.

## `loupe review ios`

Opens the Live review panel for an app in the local iOS Simulator. The panel sits beside the simulator window in Device Hub, in Xcode 27. It saves notes with a screenshot to the Loupe project. [Reviewing an iOS app in the simulator](../using/live-review.md#reviewing-an-ios-app-in-the-simulator) describes the review itself.

```bash
loupe review ios
```

The command takes no argument and no flag. Run it in the folder of your app. It reads `.loupe.yaml` in that folder for the project. Without that file, the panel lists your projects and remembers your pick for the folder. The command passes the project, the folder and its own path to the panel.

The command needs macOS and the panel of the running version. It looks for the panel in this order:

1. The `libexec` folder beside the binary, where Homebrew installs it.
2. The config folder of the CLI, in `live-review/<version>/`. On macOS this is `~/Library/Application Support/loupe/live-review/<version>/`.

In a setup other than the default, the command passes the setup to the panel. A built setup uses the
panel in `libexec/` of the setup folder only. A setup that runs the shared binary looks in `live-review/<version>/`
of the setup, then in the same folder of the default setup. The project comes from the link of the
setup first, then from `.loupe.yaml`.

On the first run, macOS asks for the Accessibility permission for **Live review**.

The command exits with status 1 and prints one of these messages:

| Message | Cause |
|---|---|
| `Live review for iOS needs macOS` | The command runs on another system, such as Linux. |
| `this is a development build of loupe, which has no installed Live review panel` | The binary has no release version. |
| `Live review is not installed; looked in <libexec> and <config folder>. Run loupe update, or reinstall loupe` | Neither folder holds the panel. |
| `could not open <panel>` | macOS could not start the app. |
| `read the project file` | `.loupe.yaml` exists and cannot be read. |

On success the command prints `Opened Live review for <folder>.`

## `loupe review` (hidden helpers)

`loupe review` holds hidden helpers for the Live review panel: `status`, `sign-in`, `projects`, `targets`, `notes` and `save`. Each prints one JSON object on stdout. They are not a stable interface, so do not script against them. Only `loupe review ios` is stable.

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
