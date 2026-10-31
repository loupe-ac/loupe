---
title: "Analytics: Metrics and Reports"
description: "The Metrics tab of the Analytics page, which charts how the agents of a project perform over time, the Time buckets tab, and the Reports tab, where an agent explains the cost and the time."
---

The **Metrics** tab of the project's **Analytics** page charts one metric of the
agents over time. It reads the [worker runs](worker-runs.md) that a bridge
reports, and the cards that they finish. The **Experiments** tab compares the
variants of each [experiment](experiments.md). On the [Reports](#reports) tab,
an agent analyses the runs and proposes changes.

Open **Analytics** in the project sidebar, or go to
`/projects/{project}/analytics/metrics`. Only the project owner can open it.

## The controls

A row of controls above the chart sets what the page shows. The page loads
again when you change a control. Without JavaScript, press **Apply**.

| Control | Values | Default |
|---|---|---|
| Metric | one metric from [the list below](#the-metrics) | **Cost** |
| Unit | **Per run**, **Per finished card** or **Per reading** | **Per finished card** |
| Statistic | **Median**, **Mean**, **Sum**, **Maximum**, **90th percentile** or **Count** | **Median** |
| Group | **By stage**, **By model**, **By variant**, **By card type**, **By bridge**, **By harness**, **By account**, **By harness version**, **By CLI version**, **By plugin version**, **By account window** or **No grouping** | **No grouping** |
| Range | **30 days**, **90 days** or **All time** | **90 days** |
| Bucket | **Per day**, **Per week** or **Per month** | **Per week** |

A control lists only the values that its metric allows. An unknown value in the
address takes the default. A value that the metric does not allow takes the
default when the metric allows the default. Otherwise it takes the first value
that the metric allows. For example, `metric=stop-rate&unit=card` shows the stop
rate per run.

## Run rows and card rows

The unit sets what one row of the metric stands for.

A run row is one closed run. Its time is the end of the run. A run with no end
takes the time when its first report arrived. **By stage** reads only the runs
of a card.

A card row is one finished card. A card is finished when it sits in a terminal
column, such as **Done**. Its time is the time when the card was completed. The
value of a card is the sum over its runs. A run that started and has no value
makes the value of its card unknown. A command run has no agent, so its missing
cost and tokens count as none.

With **By stage**, **By model**, **By variant**, **By bridge**, **By harness**, **By account**, **By harness version**, **By CLI version** or **By plugin version**, a card gives
one row for each group that its runs used. A card whose runs used two variants
gives two rows. Each row of a card outcome metric carries the whole outcome of
the card. Thus the card counts once for each variant. This holds for the card
metrics that the workflow template declares, such as **Merge rate**.

The range keeps the rows whose time falls inside it. The metrics start with the
oldest run that the server held at the upgrade. A run that the
[retention](../reference/worker-runs.md#retention) sweep deleted before then is
not counted.

## The metrics

| Metric | What it measures | Units |
|---|---|---|
| **Cost** | the cost in US dollars. It is unknown when a started run has no price | run, card |
| **Input tokens** | the input tokens that the model read | run, card |
| **Output tokens** | the output tokens that the model wrote | run, card |
| **Cache read tokens** | the tokens that the model read from its prompt cache | run, card |
| **Cache write tokens** | the tokens that the model wrote to its prompt cache | run, card |
| **Duration** | the time from the start to the end of a run | run, card |
| **Runs** | the number of worker runs on a finished card | card |
| **Stop rate** | the share of runs that reached an outcome and blocked, failed, gave no result or gave up | run |
| **Time in &lt;bucket&gt;** | the tool time of a run in one [time bucket](#time-buckets), such as **Time in git** | run, card |
| **Time in &lt;bucket&gt;, subagents included** | the tool time of a run in one time bucket, with the calls of subagents | run, card |
| **Calls in &lt;bucket&gt;** | the number of tool calls of a run in one time bucket, subagent calls included | run, card |
| **Harness events: &lt;kind&gt;** | the number of [harness events](#harness-events) of one kind in a run, such as **Harness events: diagnostics** | run, card |
| **Session usage of a run** | how many points of the 5-hour [usage window](#account-usage) a run used, from its first to its last reading | run |
| **Week usage of a run** | how many points of the weekly usage window a run used, from its first to its last reading | run |
| **Account usage** | the [use of a usage window](#account-usage) of a bridge account, as a percentage | reading |

The workflow template of the project can declare more metrics. They appear
after the built-in ones, with the label and the description from the template.
Their unit is card, and they take the groups **By variant**, **By card type** and
**No grouping**. The key of a template metric stays stable, so an address that
names it keeps working. A project whose template declares no metric shows none.

The shipped Lifecycle template declares **Fix rounds**, **Design review rounds**,
**Merge rate**, **Hours to merge**, **Hours to agent review pass** and **Hours
waiting for approval**. The shipped Simple template declares **Merge rate** and
**Hours to merge**. [Workflows](workflows.md) describes how to declare your own.

The metric picker lists one **Time in** metric for each bucket that has data
on a run of the project. A bucket has data on a run when the run stores a time
for it, and that time can be 0 ms. The time is unknown for a run with no
tool-call data, such as a command run or an old run. An address can name any
valid bucket that has no time. The Metrics tab then shows that bucket, with 0
on each run with data. A card adds the time of its runs with data.

The **Time in** metric counts the main session alone, because the time of a
subagent already sits inside the call that started it. The two other metrics
of a bucket include the subagents. **Time in &lt;bucket&gt;, subagents
included** merges the calls of the main session and of its subagents, so it can
be longer than the run. **Calls in &lt;bucket&gt;** counts every call, also a
call that has no duration. Their keys are `bucket-time-all:<name>` and
`bucket-calls:<name>`. A run that Loupe has not computed since these metrics
came has no value for them. Run `php bin/console app:bridge:recompute-bucket-times`
once to fill them for past runs. It queues one computation for each project.

### Account usage

A bridge reads how much of its usage windows an account has used, and sends the
readings to Loupe. **Account usage** charts them. One row is one stored reading
of one window of one account. Its time is the time when the bridge read it.

The group of a row is `<bridge name>/<account> <window>`, such as `desk/claude
session`. The window is `session` for a 5-hour window, `week` for a 7-day window,
or its length for any other window. Only **By account window** is available. A
bridge with no name has no rows, because the group needs a name. A reading that
gives no use, such as one that only says the account is at its limit, has no row.

The value is a percentage from 0 to 200. A value above 100 means use past the
limit, such as extra usage. The metric takes **Maximum**, **Mean**, **Median**,
**90th percentile** and **Count**, and no **Sum**. **Maximum** per bucket shows the highest use of each day or
week.

**Session usage of a run** and **Week usage of a run** compare the runs of a project. The value of a
run is the use at its last reading minus the use at its first reading, among the
readings of the account of the run between its start and its end. A run needs
two readings with a use to have a value. Otherwise the value is unknown. The
figure is approximate. A parallel run, or a session of your own on the same
account, draws from the same window and shifts it.

### Harness events

The harness writes small events beside the messages of a run, such as
diagnostics, hook results and queued commands. The bridge counts them for each
kind. Only the kind names and the counts leave the machine. No event text is
sent. [Harness events](../extending/cli-bridge.md#harness-events) says how the
bridge names and counts them.

The metric picker lists one **Harness events** metric for each kind that a run
of the project reported, the kind with the largest total first. The key of a
metric is `harness-events:<kind>`, such as `harness-events:diagnostics`. A kind
is 1 to 64 characters of a-z, 0-9, `_`, `.` and `-`.

The count is 0 for a run that reported events and none of this kind. The count
is unknown for a run that reported no events, such as a run of an older bridge.
An address can name any valid kind that no run reported. The Metrics tab then
shows that kind with 0 on each run that reported events. A card adds the counts
of its runs with a known count. It is unknown when no run of the card has one.

The dollars are the API list price that claude reports. On a subscription, you
do not pay this amount. A duration shows in milliseconds, seconds, minutes or
hours. A duration that a template declares shows in hours. A rate shows as a percentage. With
**Count**, the summary and the chart show a number of rows for every metric.

**Account usage** takes **Maximum**, **Mean**, **Median**, **90th percentile** and **Count**. **Stop rate** takes only **Mean** and **Count**. A rate that a template declares
takes the same two statistics.

## Statistics, groups, ranges and buckets

| Statistic | Meaning |
|---|---|
| **Median** | the middle value |
| **Mean** | the average value |
| **Sum** | the total of the values |
| **Maximum** | the highest value. Only a percentage metric offers it |
| **90th percentile** | the value that 90% of the rows do not pass |
| **Count** | the number of rows with a known value |

A group splits the rows into one series for each value:

| Group | A series for each |
|---|---|
| **By stage** | work kind, such as `implement` or `fix` |
| **By model** | model |
| **By variant** | variant of an experiment |
| **By card type** | type of card |
| **By bridge** | bridge, by its name. A bridge with no name shows the end of its id |
| **By harness** | harness that ran the run, such as `claude-code` or `codex` |
| **By account** | named harness account of the bridge, such as `claude` |
| **By harness version** | version of the harness that ran the run, such as `2.1.301` for Claude Code |
| **By CLI version** | version of the Loupe CLI that started the run |
| **By plugin version** | version of the Loupe plugin that the worker loaded, such as `5.5.0` |
| **By account window** | usage window of a bridge account, such as `desk/claude session`. Only **Account usage** takes it |
| **No grouping** | one series for all the rows |

A run from a bridge that does not send a version has no value for the three
version groups. The values start with the first run of a bridge that sends them,
and no earlier run gets one. The three groups split run metrics only. A card
outcome metric keeps its groups, because a card can span runs of several
versions.

The rows with no value for the group form the series **No group**, which comes
last.

The range is **30 days**, **90 days** or **All time**, back from now. A bucket
starts at midnight UTC. A week starts on the ISO Monday, and a month starts on
the first day of the month.

## The summary and the chart

The summary shows one figure for each series: the statistic over all the rows
of the series in the range. Below the figure, it shows the number of rows in
the series.

The chart shows one bar for each bucket of each series. The bars of the series
in a bucket stand side by side. The time axis shows only the buckets with rows.
A legend names the series when there is more than one. With more than eight
series, the first seven keep their colours, and the rest share one grey. The
value axis of a rate stops at 100%, except with **Count**.

Point at a bar, or move to it with the Tab key, to see its bucket, its series,
its value and its number of rows with a known value.

## Unknown values

A row whose value is unknown stays out of
every statistic, and **Count** does not count it. A series with no known value
shows **unknown** as its figure. With **Count**, it shows 0.

## When there is nothing to show

When no row falls in the range, the page shows **No runs are recorded yet**. The
chart fills when a bridge reports worker runs. Connect a bridge on the Agents
page, or pick a longer range.

## Share a view

Each view of the page has an address that you can share. The form puts every
control into the query string. Copy the address to share or bookmark a view. An
address that leaves out a control shows that control at its default.

The old address of the Cost tab, `/projects/{project}/worker-runs/cost`, opens
this page. It shows the mean cost per finished card. The range and the bucket of
the old address stay when the page knows them.

## Through the MCP

The MCP tool `metric_query` returns the same numbers as this page, and
`metric_list` lists the metrics, with one `harness-events:<kind>` entry for each kind of the project. See [the MCP tools](mcp.md#what-the-tools-do).
Over the MCP, the group of a bridge is its id, and the page shows its name.

## Reports

The **Reports** tab lists the analyses of the project, newest first. An
analysis is a piece of agent work about the project rather than about a card.
The agent reads the worker runs, writes a report document, and proposes
changes. Go to `/projects/{project}/analytics/reports`. Only the project owner
can open it.

An analysis needs a bridge that sets `appPrompts: true` or has a work entry
for the `analysis` subject.
[Work requests](../extending/cli-bridge.md#work-requests) shows the entry. The
`loupe-analysis` skill of the Loupe plugin does the work. For the **Experiment**
topic, the agent reads the comparison, looks for causes other than the variant,
and recommends a variant or more runs.

### Start an analysis

Fill the **Analyse** form, and press **Analyse**.

| Field | Values |
|---|---|
| Topic | **Cost**, where the cost of the workers goes, **Time**, where the time of the workers goes, **Experiment**, what the comparison of one experiment shows, or **Host**, how the machines of the bridges behaved |
| Experiment | an experiment of the project. The **Experiment** topic needs it, and the other topics ignore it |
| Range | **30 days**, **90 days** or **All time**: the runs the agent reads. The **Experiment** topic ignores it, and reads every card of the experiment |
| Model | the model of the agent, such as `sonnet` or `opus` |
| Effort | **Low**, **Medium**, **High**, **Extra high** or **Maximum** |

An empty model or effort takes the project default. The analysis opens a work
request. A bridge that reports the `subject-analysis` capability claims it.
So does a bridge that reports `app-prompts`, because the request carries the
prompt that Loupe ships. The request model and effort replace the model of the
work entry or the default model.

A **Time** analysis reads the bucket times of the runs, the slowest tool calls
and the idle gaps. It names repeated steps and polling loops, and it sets apart
a slow call that is no fault of the worker. It proposes a card for each fix. It
also proposes a rule for each slow call that no [time bucket](#time-buckets)
takes yet.

A **Host** analysis reads the host metrics of each run. It looks for runs that
suffer when too many run on one bridge at the same time, and for runs on a
machine on battery or one that slept. It also looks for CPU and memory
pressure. The analysis needs host samples, which are off by default.
[Host samples](../extending/cli-bridge.md#host-samples) says how to turn them
on. With no samples, the report says so, and compares only the runs that
shared a bridge, because Loupe counts those from the run times.

### States and reasons

Each analysis shows its state, its model and effort, its cost so far, and the
time it started.

| State | Meaning |
|---|---|
| **Waiting for a bridge** | No bridge has claimed the work yet |
| **Running** | A bridge claimed the work, and the agent runs |
| **Done** | The agent sent its report |
| **Failed** | The work ended with no report. The reason follows the state |
| **Paused** | No bridge took the work within 2 hours. Nothing runs it again |

A failed analysis shows one of these reasons:

- `request-refused`: the server refused the work request.
- `request-failed`: an error stopped the server before it opened the work request.
- `no-report`: the work ended, and the agent sent no report.
- The reason of the bridge, or `refused`, when the bridge refused the work.

A paused analysis shows `no-bridge-took-work`. A failed or paused analysis is
final. To try again, start a new analysis. The cost is the sum of the runs
of the analysis. It shows **unknown** when no run has a known cost.

### The report and the proposals

A done analysis links its report document. The `loupe-analysis` skill tags it
`analysis`. Open it to read and comment, as on any document.

Below the analysis, each proposal shows its title, its detail and its
estimated saving. A proposal is **Proposed** until you act on it.

- Press **Create the card** to put a feature card in the backlog of the board.
  The proposal then reads **Card created**, with a link to the card.
- Press **Dismiss** to close the proposal. Type an optional reason first.

A proposal of the kind `bucket-rule` shows its pattern and its bucket. Press
**Create the rule** on it to add the rule to the [time buckets](#time-buckets)
of the project. The proposal then reads **Rule created**. Loupe
refuses a proposal whose pattern or bucket is not valid, and a proposal for a
project that already holds 50 rules. Dismiss such a proposal.

### Analysis settings

Open **Analysis settings** to set the defaults of the project.

| Setting | Meaning |
|---|---|
| Default model | the model of a new analysis that names none |
| Default effort | the effort of a new analysis that names none |
| Programs with subcommands | a comma list, such as `git, npm`. For these programs the second word joins the signature of a shell command |
| Collect the full text of each tool call | the bridge sends the full text of each tool call, so an analysis can read it |

An empty default model or effort takes the instance default. The
`insights.default_analysis_model` feature flag holds the instance model, and
its default is `sonnet`. The `insights.default_analysis_effort` feature flag
holds the instance effort, and its default is `medium`. Change them at **`/admin/feature-flags`**.
The full text switch is off by default.

A blank list of programs takes the instance list. The
`insights.subcommand_programs` feature flag holds it. A program name has 1 to
40 characters of letters, digits and `.`, `_`, `+` and `-`. A list holds at
most 50 names.

### Time buckets

The **Time buckets** tab splits the tool time of each worker run into buckets.
A rule has a pattern and a bucket. The pattern is a glob that matches the
signature of a tool call, such as `git *` or `grep`. A star matches any run of
characters and a question mark matches one. A bucket name has 1 to 64
characters of lower case letters, digits, `_` and `-`.

The first rule that matches a call takes it. A call that no rule takes counts
in the bucket `other`. Use **Move up** and **Move down** to change the order.
A project holds at most 50 rules. After each change, Loupe computes the bucket
times of the runs of the project again, in the background. The computation
stores three figures for each run and bucket: the main-session time, the time
with subagents, and the number of calls with subagents.

The section **Time per bucket** shows the time in each bucket over the closed
runs of a range. It lists the buckets that the rules name, in rule order. Then
it lists `other`, which always shows. Then it lists the other buckets with
time, by name. A run with no tool-call data stays out of every figure except
the count of closed runs.

| Figure | Meaning |
|---|---|
| **Total** | the time of all the runs with data in the bucket |
| **Median per run** | the middle time of a run with data. A run with no time in the bucket counts as 0 |
| **Runs with data** | the runs with tool-call data, out of all the closed runs in the range |
| **With subagents** | the time of all the runs in the bucket, subagent work included. It shows **No data** until Loupe computes the buckets of the project again |
| **Calls** | the number of calls in the bucket, subagent calls included. It shows **No data** until Loupe computes the buckets of the project again |
| **Share** | the part of the time of all the listed buckets |

The range is **30 days**, **90 days** or **All time**, and its default is
**90 days**. When no run in the range has tool-call data, the section shows
**No data yet** in place of the table. Otherwise a bucket with no time shows its figures as 0 ms,
with the share 0%, so you can tell it apart from missing data. Each bucket has
the link **Open in Metrics**. It opens the Metrics tab on the
time in that bucket for each run, with the median and the same range.

The MCP tools `analysis_get` and `analysis_report` let the agent read and
finish an analysis. `analytics_settings_get` and `analytics_settings_update`
read and change the settings. `bucket_rule_list`, `bucket_rule_create`,
`bucket_rule_move` and `bucket_rule_delete` read and change the time bucket
rules, as the Time buckets page does. See [the MCP tools](mcp.md#what-the-tools-do).
