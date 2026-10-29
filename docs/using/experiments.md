---
title: "Analytics: Experiments"
description: "The Experiments tab of the Analytics page, which compares the variants of each experiment a bridge runs."
---

An [experiment](../extending/cli-bridge.md#experiments) splits the cards of a
kind of work between models or accounts. The **Experiments** tab of the project's
**Analytics** page shows how each variant did.

Open **Analytics** in the project sidebar, then the **Experiments** tab.

## The list of experiments

The list shows one row for each experiment that a run or a pin names. A row
shows the name, the number of cards and the time of the last run. The latest
run comes first. Select a name to open the comparison of that experiment.

A project with no experiment shows how to start one. Give a worker entry of the
`work:` map in `rules.yaml` its variants. The kind of the entry names the
experiment, unless the entry sets `experiment`. To start a new trial on a kind
that has an old one, set `experiment` to a new name. The old experiment keeps
its page. A variant sets a `model` or an
[`account`](../extending/cli-bridge.md#accounts), and it can set `permissions`.
A variant with no model takes the model of its account, or the `--model` flag
of the bridge when the account names none.

## The comparison

The **Comparison** tab starts with the **Goal** panel. The goal is the metric
that the experiment tries to improve, and the direction that is better: `lower`
or `higher`. The panel names the better of the first two variants by name and
says by how much, for example "sonnet is 22% faster than opus". It also shows
the value of each variant and the likely range of the change.

The panel has three states:

| State | Meaning |
|---|---|
| Not enough cards yet | A variant has fewer than 5 cards behind the figure of the goal. A rate counts the finished cards. A value for each merged card counts the merged cards that have a value. The panel shows the count of each variant, up to 5 |
| Not clear yet | Each variant has at least 5 cards behind the figure, and the likely ranges of the two variants overlap. The better variant only leans ahead |
| Clear | Each variant has at least 5 cards behind the figure, and the likely ranges do not overlap |

Close values do not make the answer clear, unlike in the metrics table. The panel says "The variants are level" when the two values are equal, and "under 1%" when the change rounds to zero. The large line uses "faster" for a duration, "cheaper" for the cost, "fewer" for a count, and "lower" or "higher" for any other metric. A goal
that is a rate, such as the merge rate, gives the change in percentage points
(pp) instead of a share, for example "6 pp higher".

The **Guards** table follows the panel. A guard is a metric that the winner must
not make worse. Each row shows the change from the first variant to the second,
and the likely range of that change. A rate shows both rates, for example
"92% to 86%", and its range in percentage points (pp). A guard with no data
shows a dash. The page has no Guards table when the experiment has no guard.

By default the goal is the cost per merged card, and lower is better. The cost
counts every worker run of the card, such as the fix rounds and the reviews, and
not only the runs of the experiment. Use the `covered-cost` key as a goal or a
guard to compare the covered runs alone. The
guards are the merge rate and the fix rounds per merged card, when the workflow
template of the project declares them. An experiment can set another goal and
other guards with a `headline` in `rules.yaml`:

```yaml
work:
  implement:
    prompt: Implement card {cardNumber}.
    variants:
      - {name: opus, weight: 1, model: opus}
      - {name: sonnet, weight: 1, model: claude-sonnet-5-5}
    headline:
      goal: {metric: hours-to-merge, better: lower}
      guards: [merge-rate, fix-rounds]
```

A goal with a key that no metric has, or a goal with no `better`, names no
winner. The panel then says what is wrong, for example "The headline goal
hours-to-mrege is not a metric of this project". A guard key that no metric has
is left out, and a note under the panel names it. The
[bridge reference](../extending/cli-bridge.md) says how the bridge checks a
`headline`. The `experiment_get` tool returns the same answer in its `headline`
field: `headline.goal` has the metric, the direction, the state, the winner and
the change, `headline.guards` has one entry for each guard, and
`headline.controls` has one entry for each control.

### Controls

A control checks that a variant got its treatment. An experiment can win on
cost while the treatment never happened. For example, a variant with a language
server can look cheaper while the language server never ran. A control names a
metric, a variant, an expectation and a share:

```yaml
    headline:
      controls:
        - {metric: "harness-events:diagnostics", variant: lsp, expect: "> 0", share: 0.5}
```

This control says that at least half of the finished `lsp` cards must show a
batch of diagnostics. A card passes when the sum of the metric over its
experiment runs meets the expectation. A run outside the experiment, such as a
later review, never counts. So a control on `cost` or `runs` reads the covered
runs, while the Metrics table counts every worker run of the card. A card with no
value for the metric is left out. The control passes when the cards that pass, over the cards with a
value, reach the share. A control can read any metric that the Comparison tab
can show. The unit is the unit of the metric: dollars for a cost, milliseconds
for a built-in duration, and hours for a duration that the workflow template
declares.

A control that does not pass holds the goal. The goal panel names no winner and
says why in its large line:

| Large line | Cause |
|---|---|
| Treatment not applied | A control failed. A red chip reads "Control failed" |
| Control has no data yet | No finished card of the variant has a value for the metric of a control |
| The control `<key>` is not a metric of this project | The key of a control names no metric, or a bucket that the project does not have |

The line under it counts the controls that hold the goal, and shows the goal
value of each variant. When more than one cause applies, the panel shows the
first in the order of the table. This message wins over "Not enough cards yet".

A held goal changes no figure. Every card and every run stays in the goal, the
Guards table, the Variants table and the Metrics table. A `harness-events` key
is never unknown. A kind that no run reported shows no data.

The **Controls** table sits above the Guards table. It shows only when the
experiment declares controls. Each row has the control with its expectation, the
variant, the share of cards that pass, the share it needs, the cards with data
over the finished cards, and a result chip: Passed, Failed or No data. A control
with an unknown metric also shows No data. The `experiment_get` tool returns the
same answer in `headline.controls`. When a control holds the goal,
`headline.goal.state` is `held`, `holdReason` names the cause, and `heldBy`
lists the keys of the controls with that cause.

An experiment covers one or more kinds of work, such as `implement` and `fix`.
The bridge sends this list with the first request of each covered run. When no
bridge sent a list, the page takes the kinds of the runs that name the
experiment.

The variants table shows, for each variant:

| Column | Meaning |
|---|---|
| Variant | the name of the variant |
| Model | every model that the experiment runs of the variant asked for, sorted |
| Weight | the weight of the variant, as the bridge last reported it |
| Cards | the cards of the variant that the figures use |
| Finished | the cards that merged or stand in a terminal column |
| Runs | every worker run of those cards, of any kind of work |
| Covered cost | the cost of the runs of the experiment of a covered kind |
| Cost | the cost of every worker run of those cards |

The metrics table has one row per metric. Each variant has two columns: the
value, and the **Likely range** around it. By default the table shows the merge
rate and the fix rounds. It leaves out a key that the workflow template of the
project does not declare. An experiment can name other metrics, and a template
metric shows with the label from the template.

An experiment can also name a bucket or a harness event metric:
`bucket-time:<name>`, `bucket-time-all:<name>`, `bucket-calls:<name>` and
`harness-events:<kind>`. The [Analytics](analytics.md) page describes the four
families. The value of a card is the sum of the values of its experiment runs.
A run with no figure adds nothing, and a card whose runs have none shows no
value. A bucket or a kind that no run has shows no data, not an error.

| Metric | Meaning |
|---|---|
| Merge rate | the merged cards over the finished cards |
| Stop rate | the covered runs of the experiment that ended blocked, failed, with no result or gave up, over the covered runs that reached an outcome |
| Fix rounds per merged card | the fix requests of a merged card. A row for each reason follows: a conflict, failed checks, requested changes or a failed agent review (`agent-review`) |
| Cost per merged card | the cost of every worker run of a merged card |
| Covered cost per merged card | the cost of the runs of the experiment of a covered kind, for a merged card |
| Runs per merged card | every worker run of a merged card |
| Output tokens per merged card | the output tokens of every worker run of a merged card |
| Run time per merged card | the run time of every worker run of a merged card |
| Hours from open to merge | the time from the first pull request of a merged card to the last merge |
| Design review rounds | the design revise requests of a finished card. The Lifecycle template declares it |
| Hours to agent review pass | the time from the first pull request opening to the last agent review pass. The Lifecycle template declares it |

The rows for the merge rate, the fix rounds and the times exist only when the
workflow template of the project declares them.
The cost and the output tokens use only the merged cards whose worker runs
reported usage. A card with no usage is not a card that cost nothing, so it
stays out of these two samples. The same applies when one run of the card
started and reported no usage, because the sum of the others is too low. It still counts in the other metrics. A card
with a usage row for a model that has no price has an unknown cost, so it
stays out of the cost sample and the variant total.

The per-card figures use the cards that ended with the outcome that the template
marks as delivered, which is `merged` in the shipped templates. A card that a person
moved to a terminal column is finished, but it did not end with that outcome.

## The likely range

The likely range holds the true value 95 times in 100. A rate uses the Wilson
interval. A value per merged card uses a bootstrap of the mean. The bootstrap
has a fixed seed, so a page shows the same range on each load.

A metric compares the first two variants by name. It gives a clear answer when
each of the two variants has at least 5 cards behind its value, and one of
these is true:

- The two ranges do not overlap.
- The two values are within 10% of each other.

A rate counts the finished cards. A value per merged card counts only the
merged cards that have the value, such as a cost or the two times of a merge.

A metric with no clear answer shows a **Too few cards** chip. An experiment
with one variant never gives a clear answer.

Press **Analyse this experiment** below the metrics to ask an agent to explain
the comparison. The link opens the [Reports](analytics.md#reports) tab, with
the topic **Experiment** and this experiment already chosen.

## Cards that are left out

The figures leave out a card that does not give a fair comparison. The line
below the metrics shows how many cards are left out, and links to them.

| Reason | When |
|---|---|
| Switched variant | a run of the card moved it from a variant that the rule no longer offered |
| Mixed variants | the runs and the pin of the card name more than one variant |
| Covered work before the test | a worker run of no experiment or of another experiment did a covered kind of work on the card before the first run of this experiment |
| Covered work without the variant | a worker run of no experiment or of another experiment did a covered kind of work on the card at or after the first run of this experiment |
| Run before the card history | the first experiment run of the card is older than the card history of the project |
| No experiment run | a pin puts the card in the experiment, and no experiment run worked the card |

## The Cards tab

The **Cards** tab lists the cards of the experiment, 20 to a page, the latest
run first. A row shows the card, the variant, the column, the runs, the covered
cost, the fix rounds, the cost, and a tag for each reason that leaves the card out. The runs
and the cost count every worker run of the card. A cost shows a dash when no run
of the card reported usage, or when the cost is unknown. Select a card to open it on the board.

The filters above the list show all the cards, the kept cards of one variant,
or the cards that are left out. Each filter lands in the URL, so a filtered
view is a link you can share.
