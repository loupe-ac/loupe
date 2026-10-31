---
title: "Activity: Runs"
description: "The Runs tab of the Activity page, which shows what a command-line bridge told a project about the workers it ran, on Claude Code or Codex."
---

A [command-line bridge](../extending/cli-bridge.md) runs a worker for each
board event one of its rules matches. The worker runs on Claude Code or on
Codex, as its account says. Every worker the bridge reports becomes one row on
the **Runs** tab of the project's **Activity** page. An interactive session on
a card gets a row too, as
[Interactive sessions](#interactive-sessions) says.

Open **Activity** in the project sidebar, or go to
`/projects/{project}/worker-runs`. Anyone who can view the project can read it.
The sidebar link shows how many runs are open, and it opens the Runs tab with the
open runs filter on. The number counts queued, resumed, preparing, running and
stopping runs, of agents and of interactive sessions. It updates as runs change.
The **Agents** link shows how many workers are busy over the worker limit, such as
`1/3`. It adds up the worker pools of the bridges that follow the project, so a
bridge that also serves another project counts the workers of both. It shows
nothing when no bridge reports worker pools. It updates as runs and pools change,
with a delay of up to 10 seconds for a pool change.
The open runs also show on the [Workshop](workshop.md), under In motion.

The Activity page has two tabs. **Runs** lists the runs, and the rest of this
page describes it. **Events** lists the [project events](activity.md).
The [Analytics](analytics.md) page charts the metrics of the runs and compares
the variants of each [experiment](experiments.md).

## What a row shows

| Column | Meaning |
|---|---|
| Work | the number of the card the worker was started for, then its title. A card that is gone, or a board that is off, shows the number alone. An interactive run adds the tag **Interactive session**, and a command run adds the tag **Command** |
| Outcome | the state of the run, from the list below. A run with a failure reason shows a help icon, and the reason shows when you hover over or focus the outcome |
| Work kind | the kind of the work request the run ran, such as `implement` or `fix`, or the name of an interactive session. A run from before the work map shows **None**. A long name is cut short, and the drawer shows it in full. A run that names a worker pool adds a tag with the pool, such as **quick pool**. The harness, the account and the model each add a tag when the bridge reported them, such as `codex`, `work` and `gpt-5` |
| Duration | how long the worker ran. A run that is still open shows how long it has run so far, and an open interactive run shows "running for" in front. A run with no start, or a run that closed with no reported end, shows nothing |
| Started | how long ago the worker started, on the bridge clock. Hover over it to see the exact time. A run that has not started shows when its first report arrived |

Select a row to open its drawer. The drawer holds the detail the row
leaves out: the bridge, the exit code, the run a resume continues, the
failure reason and the output.

The drawer also shows a **Metrics** block from the metrics row of the run. The
block gives the cost, the input, output, cache read and cache write tokens, the
model and the duration. A run with no price shows the cost as **unknown**. A
token count or a model that the run did not report has no line. A command run
shows no **Metrics** block, and a run with no metrics row shows none either.

A bridge runs its workers in named worker pools, and each report of a run names
its pool. The row and the drawer show the pool that the last report named. A
run from an older bridge, and an interactive run, name no pool and show none.

A work entry of a bridge can split its runs between the variants of an
experiment, and each card keeps the variant of its first run. The drawer of such
a run shows an **Experiment** line after the work kind, such as `impl-model / sonnet
(claude-sonnet-5-5)`. The line gives the experiment, the variant and the model
the variant asked for. When the entry no longer offers the variant of the card,
the card moves to a new variant. The line then adds a note, such as `, switched
from opus`. A run with no experiment shows no line.

A bridge can run its workers on more than one harness, such as Claude Code
(`claude-code`) or Codex (`codex`), and on named accounts. Each report of a run
can name the harness, the account, the model and the id the harness gave the
session. It can also name the version of the harness, the version of the Loupe
CLI and the version of the Loupe plugin that the worker loaded. The drawer shows
each version that the run has. A run from a bridge that sends no version shows
none. A value can arrive in a later report, so the row and the drawer show
it from that report on. A run from before this release shows the harness
`claude-code`. A command run runs no harness and shows none of these.

A run that never started carries the reason instead of an exit code, such as a
missing `claude` binary, or a terminal launcher that failed.

A work entry of a bridge can run a command with no agent, such as a script that removes
the worktree of a finished card. See
[Command action](../extending/cli-bridge.md#command-action). Its run shows the
tag **Command** on this page, in the drawer, in the runs of a card and on the
Workshop. A command run has no session and no cost. It succeeds when the
command exits with code 0, and fails otherwise. The output of a failed run
starts with the reason, such as a timeout.

The list reads newest first, by when the first report of each run arrived, 20
runs to a page.

## The states of a run

| State | Meaning |
|---|---|
| **Queued** | the bridge accepted the event, and the run waits for its turn |
| **Replaced** | a newer event for the same card and rule took the place of this run |
| **Resumed** | the ask the session waited on closed, and the bridge resumes the session |
| **Skipped** | the session already read its answers, so the bridge did not resume it |
| **Preparing** | the bridge runs the `before` command of the rule, and the worker has not started |
| **Running** | the worker runs |
| **Stopped at a usage limit** | the agent account reached its usage limit, and the bridge gave the work back to Loupe. The work request is open again |
| **Stopped by a network outage** | the bridge lost its network, and it gave the work back to Loupe. The work request is open again and waits five minutes |
| **Stopping** | a person asked the bridge to stop the run, and the worker is still ending |
| **Stopped** | a person stopped the run, and the bridge does not resume it |
| **Waiting for a person** | a bridge from before the workflow engine capped the run. The bridge no longer sends this state |
| **Dropped** | the bridge stopped, the work of its project died, or a reload removed the work entry, before the run started |
| **Succeeded** | the worker exited with code 0, with a structured result. A command run succeeds on exit code 0 alone |
| **No result** | the worker exited with code 0, with no structured result |
| **Unfinished** | the worker said that its work still runs or remains |
| **Blocked** | the worker said that it cannot go on without a person |
| **Gave up** | the run did not finish, and the bridge already ran every resume the rule allows |
| **Failed** | the worker exited with any other code |
| **Never started** | the worker process never ran |
| **Timed out** | the bridge stopped sending its heartbeat while the run was open |
| **Lost** | the bridge reconnected, and it no longer holds the run |
| **Closed** | the interactive session ended, a person closed it, its card moved to another column, or Loupe found no sign of life from it. The drawer shows the reason |

Queued, Resumed, Preparing, Running and Stopping are open states. A bridge that dies cannot close
its runs, so Loupe closes them. **Timed out** is a guess: a bridge can go quiet
and come back, and a later report from it replaces the guess. **Lost** is a
fact: the bridge came back without the run, so the run can no longer end. See
[the worker run API](../reference/worker-runs.md#timed-out-and-lost) for the
rules.

The structured result is the status and the summary that every worker prompt
asks for. A **No result** run exited cleanly but may have stopped before its
work was done, so read its output. A run from an older bridge carries no result
check, and its outcome comes from the exit code alone.

## Resumed runs

Loupe decides each resume, and the bridge resumes nothing on its own. A run that
says **Unfinished** refuses its work request, and the workflow retries the
request on the same session. When an [inbox](inbox.md) ask of a session closes,
Loupe asks the bridge to resume that session. A person can also resume a run, as
the next section says. Each resume is a new row, and its drawer links to the
run it continues. A run from before the workflow engine can show **Gave up**.

A resumed run also ends a workflow pause on its card. The pause ends when the
resumed run first reports, and the inbox item of the pause closes with it.

A resume that a person or a closed ask sends runs on the agent account that the
run started on. The bridge refuses it when that account is gone from its rule
file, or names another agent tool. A retry of an **Unfinished** run uses the
account that the rule names now.

## Usage limits

A usage limit of an agent account does not fail a run. The bridge reads the
usage of the account while the run goes. When the run stops at a limit, the run
ends in the state **Stopped at a usage limit**. The bridge then hands the work
back to Loupe. The work request opens again, and Loupe spends no retry on it.

The card shows **Waiting**. The marker names the account, the window, the use
and the time of the reset, such as "claude · Week · 100 % · resets Sat 1 Nov at
17:00". A page shows the reset time in the time zone of your browser. The API
and the link tooltips show it in UTC, and name the zone. See
[the usage-limit marker](workflows.md#markers).

Loupe offers no work to an account that is at its hard limit. It also offers no
new work to an account at or above its stop value. The stop value is 95 % of a
window by default. Set `stopAt` on the account in `rules.yaml` to change it.
Runs that already go continue at the stop value.

The work starts again by itself in one of these cases:

- The reset time of the account passes.
- A new reading shows that the limit lifted early.
- Another bridge has an account with room for the work. That bridge takes the
  work at once.

A run that did at least one assistant turn before the limit continues on its own
bridge. The bridge resumes the same session, so the agent keeps its context. A
run that did no work yet can start on any bridge with room.

## Network outages

A network outage does not fail a run. When the bridge loses its connection to
the agent service, the run ends in the state **Stopped by a network outage**.
The bridge hands the work back to Loupe. The work request opens again, and
Loupe spends no retry on it.

The card shows **Waiting** with the marker "Work waits: the bridge lost its
network". The card shows the same marker when a bridge holds the work and sends
no heartbeat. Loupe offers the work again after five minutes. A run that did at
least one assistant turn waits for its own bridge, and the bridge resumes the
same session. After the wait, a run that did no work yet can start on any
bridge. The marker stays after the wait while the bridge of the run is
offline, or while no bridge is online. See [the bridge-disconnected marker](workflows.md#markers).

If a bridge stops sending its heartbeat while it holds a work request, the
claim ends after its lease. The request then waits five minutes in the same
way, and it keeps the session of the run.

## Stop, resume and cancel

The project owner can control a run from the runs section of a card page and
from the drawer of a run. The card page lists only the runs in progress, so it
offers **Stop** and **Cancel request** for a stop. Resume an ended run, or run
a command again, from this page. Other people see the labels and no controls.
An interactive run offers **Close session** in place of the other controls. It
shows in the runs section of a card, in the drawer of the run, and as a small
close icon in the **Outcome** cell of the row in the list.

| Control | When it shows | What it does |
|---|---|---|
| **Stop** | the run is Queued, Resumed, Preparing or Running | asks the bridge to stop the run |
| **Resume** | the run ended as Blocked, Gave up, Failed, No result, Unfinished, Timed out, Lost, Stopped or Waiting for a person, and it has a session | asks the bridge to continue the session as a new run |
| **Run again** | a command run ended as Failed, Timed out or Lost | asks the bridge to run the command again as a new run |
| **Close session** | an interactive run is Running | closes the session at once and shows it as **Closed**. It sends nothing to a bridge, and the terminal of the session stays open |
| **Cancel request** | a stop, a resume or a rerun still waits for the bridge | withdraws the request |

A request waits until the bridge takes it. The row then shows the label of the
request: **Stop requested**, **Resume requested** or **Run again requested**.
When the bridge sends no heartbeat, the label ends with **, bridge offline**. A request that waits longer than the
`bridge.command_ttl_minutes` flag expires. The flag is 15 minutes by default.
See [Pause and commands](../reference/bridge-heartbeat.md#pause-and-commands).

The row shows a notice when a request did not work. An expired stop shows
**The stop request expired before the bridge took it.**, and an expired resume
shows the same text for a resume. An expired rerun shows **The request to run
again expired before the bridge took it.** A refused request shows **The bridge
refused:** and the reason the bridge gave, such as a session that is not on its
machine. The notice goes when a person sends a new request, or when the control
no longer applies to the run. A queued run of a paused bridge shows
**Waiting: bridge paused**.

A control can show and be disabled. Point at it to read the reason. When the bridge does not
report the `commands` capability, every control is disabled with **Update the
bridge to 1.5.0 or later to control its runs.** **Run again** is also disabled
with **Update the bridge to run a command again.** when the bridge does not
report the `rerun-command` capability.

The bridge refuses **Run again** when the rule of the run is gone or no longer
runs a command. It also refuses while the card has a run that is open on that
bridge. It refuses when the command of the rule reads a value of its first
event, such as `{to}`, because a rerun knows only the card and the project.
The bridge never runs a failed command again by itself.

A stop ends one run and holds nothing. The next event of the card can start a
new worker on it. To keep agents off the card, select **Make unmanaged** on
the card page. Loupe refuses a resume of a run while its card is unmanaged,
with **This card is unmanaged. Select Manage again first.** See
[Managed and unmanaged cards](board.md#managed-and-unmanaged-cards).

A cancel works only while the bridge has not received the request. A bridge
that is online receives a request in about a second, and a later cancel does
not recall it. Cancel is for a request that waits on an offline bridge.

A stop reaches the process group of the worker only. Work that the worker
started in another process tree keeps running, such as a PHPUnit run inside a
Docker container. A resume continues the session with a fixed prompt.

### Through the MCP

An agent can read the runs and the bridges, and stop, resume and cancel, through
the [MCP endpoint](mcp.md#what-the-tools-do). `worker_run_list` and
`worker_run_get` read the runs, and `bridge_list` reads the bridges.
`worker_run_stop`, `worker_run_resume` and `bridge_command_cancel` send and
withdraw requests. `card_hold` makes a card unmanaged, as **Make unmanaged**
does, and `card_release` makes it managed again. The connection acts as the project
owner, on its own project only.

Each run row also carries `usage`, `model`, `experiment`, `variant` and
`metrics`. The server keeps a metrics row for each run, with its cost, its
tokens, its model, its duration and its outcome. The metrics row stays after the
[retention](../reference/worker-runs.md#retention) sweep deletes the run.
The metrics rows start with the oldest run that the server held when it was
upgraded to this release. A run that the sweep deleted before then has no
metrics row, and the card cost total on the board still counts its usage.
`metric_query` reads the metrics rows over time, by run or by finished card, and
`metric_list` lists the metrics it takes.

The metrics row also holds the timing of the run, from the tool calls the
bridge reads from the stream of the worker. `toolTimeMs` is the time the main
session spent in tool calls, and `modelTimeMs` is the rest of the duration.
`idleGapMs` sums the pauses of more than five minutes. `subagentMs` is the time
of the subagents of the main session. `toolCalls`, `failedCalls` and
`longestCallMs` count the calls. A run whose bridge sent no tool calls holds
`null` in each of them. `worker_run_tool_calls` reads the calls of one run.
`peakContextTokens` is the largest context the main session held during the
run, in tokens. It shows how close a worker came to its context limit. A run
whose bridge sent no value holds `null`.

The metrics row also holds the host of the run, from the samples a bridge takes
of its machine. `meanCpuPct` is the mean CPU use during the run, in percent.
`peakMemBytes` and `peakSwapBytes` are the largest memory and swap use, in
bytes. `onBattery` says whether the machine ran on battery during the run.
`concurrentRuns` counts the runs of the same bridge that overlapped the run,
the run itself included. The samples are off by default, so the CPU, memory,
swap and battery values are `null` until an admin turns them on.
[Host samples](../extending/cli-bridge.md#host-samples) says how.
`bridge_host_samples` reads the samples of one run.
[Run metrics](../reference/worker-runs.md#run-metrics) defines each value.

A tool call holds its tool, its start, its duration, its error flag, and
signatures that name what it ran. For a shell command, a signature keeps only
the program name, such as `grep`. A program on the subcommand list, such as
`git`, also keeps its subcommand, such as `git status`. No other argument
reaches Loupe. A call holds its full input text only when its project collects
full text, and no setting turns that on yet.
[Tool calls](../extending/cli-bridge.md#tool-calls) gives the rules. Set
`collect: false` in the `rules.yaml` of a bridge, and that bridge sends no tool
call, no timing and no host sample.

The tools apply the same checks as the controls on this page. A resume needs a
session and an ended run in a state that can resume. A run with a request that still waits refuses a second
one, and so does a bridge that does not take commands. A refused run gives a
code and a message, and `worker_run_resume` takes up to 50 runs in one call.
There is no tool that pauses a bridge, and no tool that runs a command again.
Each run row carries its `kind`: `worker`, `interactive` or `command`. `worker_run_list` also filters by `harness`, `account` and `model`, and each row carries them with `harnessSessionId`, `harnessVersion`, `cliVersion` and `skillVersion`.

## Time buckets and harness events of one run

The drawer of a run shows two sections for a run that is not a command.

**Time buckets** has one row for each bucket of the
[bucket rules](analytics.md) that the run used. **Main session** is the time
the main session spent in the bucket, and its rows add up to the share of the
run that the [Time buckets page](analytics.md) shows. **With subagents** is the
time of every call of the run in the bucket, the calls of subagents included.
Calls that overlap in one bucket count once. This is agent time, not wall-clock
time, so the column can add up to more than the run lasted. **Calls** counts
every call in the bucket, subagents included. A bucket that only subagents used
shows 0s in the first column. The rows start with the largest time.

A run that the bridge sent no tool calls for shows a line instead of the table.
A row from before the release shows **unknown** in the last two columns until
the buckets of the project are recomputed.

**Harness events** lists each kind of event that the harness gave the agent
with no tool call, such as `diagnostics`, with its count. The count includes
subagents. The most frequent kind comes first.

`worker_run_get` returns the same figures. Each run has `buckets`, a list of
`bucket`, `mainMs`, `withSubagentsMs` and `calls`, and `harnessEvents`, a map of
kind to count. The map is `{}` when the bridge reported a run with no events.
Both are `null` when Loupe holds no data.

## A warning on the card

A card whose latest outcome is **Gave up**, **Blocked**, **Failed** or **No
result** shows a warning on the board. The warning names the state and the start
of the run output, and links to that run on this page. The newest run of the
card decides. A run of the card that the server receives after that outcome
clears the warning, in any state except **Replaced** or **Skipped**. So a queued
retry, a running worker or an interactive session clears it at once. A run that
times out clears it too. A move of the card to another column does not clear
it.

## The usage total of a card

The runs section of a card shows one **Total usage** line below its runs in
progress. The line counts every run of the card, and the finished runs too. It
also counts the usage of runs that the retention sweep deleted. A card with no
run in progress shows the line alone when its usage is known. The line shows the
cost in US dollars, then the input, output, cache read and cache write tokens.
A large count is short, such as `45.3k` or `1.2M`.

The line can carry two marks:

| Mark | Meaning |
|---|---|
| **Estimated** | the bridge counted some tokens from the session transcript, or a model has no known price. The dollar total can be low |
| **n runs have no usage** | n worker runs started and closed, and reported no usage. The total leaves them out |

An open run, a run that never started and an interactive session never count
as runs with no usage. When no model of the card has a price, the line shows
the tokens and no dollars.

A card whose runs all come from an older bridge, or are all still open, shows
**Usage unknown** instead of $0.00. A run that reported usage with no models
spent nothing, so a card with only such runs shows $0.00. See
[Usage](../reference/worker-runs.md#usage) for how a bridge reports usage.

## The cost of finished cards

The [Metrics](analytics.md) tab of the **Analytics** page charts the cost of
finished cards over time. It replaces the **Cost** tab of this page, and the old
address `/projects/{project}/worker-runs/cost` opens it.

The **Estimated** and **n runs have no usage** marks show only on the
[usage total](#the-usage-total-of-a-card) of a card.

## Interactive sessions

An interactive session that a person runs on a card calls the MCP tool
`card_run_open`. An example is `/loupe:product-design` in Claude Code. Loupe then records
an interactive run on the card, with the state **Running** and the skill name
as its rule. No bridge holds this run, so it has no exit code and no output.
The heartbeat timeout never touches it.

A work entry with `action: interactive` can open the session in a terminal
when the workflow asks for that work. The bridge then records the run first, in
the state **Running**, with the work kind and the bridge. When the session calls
`card_run_open`, it takes over that run, so the page shows one row. A launch
that fails shows **Never started**, with the bridge. Its reason holds the exit
code and the output of the launcher. See
[the command-line bridge](../extending/cli-bridge.md#interactive-action).

These actions close an open interactive run, and it then shows **Closed**:

- The session calls `card_run_close` when it ends.
- The owner selects **Close session** on the running row, in the runs section
  of the card, in the **Runs** list, or in the drawer of the run. In the list,
  the view keeps its search, filters and page. A filter that leaves out
  **Closed**, such as **Open runs**, drops the row.
- The card moves to another column. A move inside the same column closes
  nothing. The delete of a column moves its cards, so it closes their runs.
- A person deletes the card.
- Loupe finds the session gone or quiet, as [Stale sessions](#stale-sessions) says.

Each of these closes makes Loupe ask the bridges that follow the project for
the usage of the run. The bridge that holds the session transcript sums the
tokens between the start and the end of the run, subagents included, and
reports them. The run then shows its usage, marked **Estimated**, and the card
total counts it. A bridge older than this feature never gets the request. While
the run has no usage, each later `card_run_close` call from the session asks
again. A run with no bridge that holds its transcript keeps no usage.

### Stale sessions

A session that crashes, or a terminal that someone kills, cannot close its own
run. Loupe closes such a run from three signals. Each closed run shows its
reason in its row of the Runs list, and in the drawer under **Closed because**.

1. **The session ended.** The Loupe plugin for Claude Code runs a `SessionEnd`
   hook. On a clean exit the hook starts `loupe session end`, which closes every
   open run of the session in every project of your account. This needs the
   `loupe` command-line tool on the machine, signed in. The exit hook gives the
   reason **The session ended**.
2. **The session process is gone.** A bridge checks each interactive session
   that it launched, once a minute. When the Claude process of the session no
   longer runs, Loupe closes the run, with the reason **The session process is
   gone**. A run younger than two minutes stays open. The bridge cannot find a
   Codex process by its session, so a Codex session never closes this way. The
   bridge reports when its session file last changed instead, as a sign of life.
3. **No sign of life for too long.** Loupe closes an open interactive run that
   shows no sign of life for 12 hours, with the reason **No sign of life for too
   long**. The `bridge.interactive_stale_after_seconds` feature flag sets the
   time, in seconds, and it starts at 43200. Set it to 0 to switch this check
   off. Any Loupe tool call that the session
   makes through `loupe mcp` is a sign of life. A bridge that sees the Claude
   process running, or the transcript change, also counts. A session that
   reaches Loupe only through the claude.ai connector sends no session header,
   so only its explicit `card_run_open` and `card_run_close` calls count.

A wrong close costs little. The next `card_run_open` call of the session opens a
new run. A workflow rule that waits for "no session open" reacts to the close as
it does to any other close, and a card in Product design can then ask for a new
session.

An agent can also close a run with `worker_run_stop`, which marks the run closed
in Loupe. The terminal of the session stays open, because no bridge holds it.
See [the command-line bridge](../extending/cli-bridge.md#events-endpoint).

## A missing record means unknown

A missing record means "unknown". It never means that the worker did not run.

The bridge holds its retry queue in memory. A bridge that stops between a worker
finishing and its report landing loses that outcome for good. So read this page
as what the server was told, not as a complete history of every worker.

The page carries this caveat below the list, on every project.

## Bridge health

Open **Agents** in the project sidebar to inspect bridge heartbeats.
The page lists only bridges that follow the current project.
Its summary distinguishes no connections, healthy connections, stale connections, and a mix of healthy and stale connections.
Heartbeat health does not show whether an individual worker is running or available for work.

Each bridge card shows the name of the bridge as its heading. The bridge sets
the name in its rule file, and the host name of its machine is the default. A
bridge with no name shows the last 12 characters of its id. The run list, the
run drawer and the board show the same label.

Two bridges of one account cannot hold the same name. The bridge that asks
second gets no name and shows the end of its id. Its card shows a warning that
another bridge holds the name. The warning stays until the other bridge frees
the name, or until you give this bridge a different name. See
[The bridge name](../reference/bridge-heartbeat.md#the-bridge-name).

A bridge that runs worker pools reports their use with each heartbeat. The
card of the bridge then lists each pool on one line: the pool name, the
workers in use of the pool size, and the runs in the queue. The heading gives
the time of the heartbeat that carried the counts, because the counts are
correct at that time only. A later heartbeat with no pool report keeps the
counts and their time. The counts cover every project that the bridge follows, not only the
current project. A bridge that sends no pool report, such as an older bridge,
shows no pools.

A bridge that checks its accounts reports the result with each heartbeat. The
card of the bridge then lists each account: the account name, its harness, and
**Ready** or **Failing**. A failing account shows in red, with the reason the
check failed and a note. The rules that use a failing account stay off until
the account passes its check and the bridge reloads. The heading gives the time
of the heartbeat that carried the accounts. A later heartbeat with no account
report keeps the rows and their time. A bridge that sends no account report,
such as an older bridge, shows no accounts.

The page also has a **Hooks** section. It shows one block for each of your
bridges whose heartbeat names the project, the latest heartbeat first. Each
block shows the last 12 characters of the bridge id, with the full id in the
tooltip, and the time of the last heartbeat. Under it, each
[hook package](../extending/bridge-hooks.md) of the bridge has one row for each
event it defines. A row shows the package, its ref, the event, the bridge and
the time of the last run. Its chip reads OK, Failed, Timed out or Not run yet. A
failed or timed out row shows the end of the hook's output, or the error when
the hook could not start. A bridge with no hook shows "No hook is installed."
Each heartbeat replaces the rows of its bridge, so a bridge that stops keeps its
last list.

The project owner can pause a bridge. Open the menu of the bridge card and
select **Pause new work**. A paused bridge starts no queued run, and its
running workers go on. **Resume new work** ends the pause. The server keeps the
pause, so a bridge that is off applies it when it comes back. A restart of the
bridge keeps the pause too.

The health chip shows **Pausing** until the bridge reports the pause, and then
it shows **Paused**. After **Resume new work**, it shows **Resuming new work**
until the bridge reports the change. A quiet bridge shows **Stale** whatever its pause.
A **Paused** block on the card gives the time of the pause and the person who
asked for it. A bridge that does not report the `commands` capability cannot
pause, and the menu says to update it to 1.5.0 or later.

## The output

Select a row to open a read-only drawer without leaving the list.
It shows the attempt ID, card, work kind, experiment, worker pool, bridge, session, harness, account, model, harness session and duration. A run with no worker pool shows no pool row, and the harness, account and model rows show only when the bridge reported a value. The **Harness session** row shows the id the harness gave the session, such as a Codex thread id. It shows only when that id differs from the session, so a Claude Code run shows none. An interactive run shows a bridge only when a bridge launched it.
A resume also shows a link to the run it resumes.
The drawer shows the result status, the reason the bridge skipped a resume, and each extra result field the worker gave.
It lists each state the run reached, oldest first, with the time of each state, and then the time the first report arrived.
Agent identity and the triggering event remain unreported rather than inferred.
Press Escape or select **Close** to return focus to the row.

Select **Copy output** to copy the original output text.
If the browser refuses clipboard access, the drawer keeps the text available for manual copying.
The drawer of a run shows its controls, as
[Stop, resume and cancel](#stop-resume-and-cancel) says.

The drawer shows the worker's output in full, under **Output**. A run that
succeeded shows its output the same way a run that failed does, because a
reader of a run record is usually debugging.

The output is whatever the agent printed, up to 4000 characters. Nobody reviews
it before it reaches this page. It may carry file contents, paths or anything
else the agent chose to say, and the server shows it as plain text.

## Live updates

The list, the runs section of a card page and the board reload when a report
or the timeout sweep changes a run of the project. They also reload when a
person sends or cancels a request, and when a bridge answers a request or a
request expires. A pause or a bridge that reports its pause reloads them too. They also reload after the page
reconnects to the hub, for any change the page missed. This needs a Mercure hub
and the `live_updates.enabled` flag. Without them, the page shows a change on
its next load.

An open drawer holds the reload. The list reloads when you close the drawer.

## Search and filters

The search box covers the card number, the work kind and the output text.
It matches whole words and accepts quoted phrases and a leading `-` to exclude a word.
Paste a complete run ID to find that attempt in the current project.
Run IDs match without regard to letter case, and the other filters still apply.

These filters narrow the list further:

- **Outcome** keeps one state. A link saved with `outcome=succeeded`, `outcome=no-result`, `outcome=failed` or `outcome=not-started` still works. `outcome=closed` keeps the closed interactive runs. **Open runs** (`outcome=open`) keeps every queued, resumed, running and stopping run, of both kinds.
- **Bridge** keeps one bridge, and lists each bridge by its name. It appears once a second bridge has reported.
- **Harness**, **Account** and **Model** each keep the runs with one value, such as `harness=codex`. Each one appears once the runs of the project hold a second value. A filter that is set stays visible, so you can clear it.

Every control lands in the URL, so a filtered view is a link you can share.
Select **Clear** to go back to the whole list.
Search and Outcome stay on one row on narrow screens.
With enlarged text, the row scrolls horizontally when needed. Keyboard focus brings each control into view.
The Bridge filter remains available below them when space is limited.
It stays within the form width at enlarged text sizes.
Long work kinds wrap within their column, while outcome badges keep their compact height.

## A card that no longer exists

A run record holds the card identifier as a plain value rather than as a
reference, so deleting a card leaves its run history intact. The card number
still carries the link, and that link answers 404 once the card is gone. The run
row itself keeps reading correctly.

## How long a run is kept

The server keeps a run record for 180 days, counted from when the report
arrived. See [the worker run API](../reference/worker-runs.md) for the retention
window and the feature flag that sets it.
