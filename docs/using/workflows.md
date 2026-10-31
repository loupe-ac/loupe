---
title: "Workflows"
description: "How a workflow template moves the cards of a board, asks a bridge for work, and writes to GitHub."
---

Each board follows a workflow template. The template names the places of the
work, called slots, and the rules that act on a card. The workflow engine of
Loupe checks the rules of a card on each change and on a timer. A rule fires
when its condition turns true. It then moves the card, asks a bridge for work,
writes to GitHub, or pauses the card.

A card runs one piece of work of each kind at a time. A rule that asks for work
while another rule's work of the same kind runs opens nothing. It waits for that
work to end, and then asks for work of its own when its condition still holds.

A bridge does the work that the workflow asks for. It does not decide when work
runs. See [Command-line bridge](../extending/cli-bridge.md) for how a bridge
maps each kind of work to an agent.

## Templates

Loupe ships two templates.

| Template | For |
|---|---|
| **Lifecycle** | The process of this repository: product design, tech design, implementation, review and merge, with epics and blockers |
| **Simple** | Any board. A merge moves the card to the terminal column |

A new project gets the Lifecycle template. The setup form has no template field. The template creates its columns
with its slots linked. A project that existed before the workflow engine got a
template when the engine was switched on. A board that had a column for each
Lifecycle slot got Lifecycle, and every other board got Simple. A project that already had a
template got the current copy of it. The engine
treated every condition that was true at that time as handled, so nothing moved
at once.

The **Workflow** page of the project shows the template of the board, its slots
and the column each slot links to, its rules and its markers. The page is read-only. A later
release adds a way to change the template.

The template also declares the card types of the board. Each type has a key, a
label and a colour. A type can have children, and it can get a lane on the
board. Both shipped templates declare the same seven types, and only Epic has
children and a lane. Feature is the default type. The **Workflow** page lists
the types.

The condition `card.type` takes a `types` list of card type keys. It is true
when the card has any type in the list.

```yaml
when: { card.type: { types: [epic] } }
```

The older `type` parameter, which took one key, no longer exists. A template
rule that still uses `type` fails to load, and the error names the `types`
list.

A rule runs one action, or a list of actions. A single action is a list of one.
The engine runs the actions in order. It stops at the first action that refuses, and after a `move`.
A refusal backs off and retries as [Pauses and retries](#pauses-and-retries) says, and the retry runs the whole list again.
Only the actions `move`, `request-changes` and `stop-work` may share a rule with other actions,
because the engine keeps no request, ask or pause for a second action.

```yaml
then:
    - stop-work: {}
    - move: { to: tech-design }
```

The **Workflow** page shows each action of a rule on its own line.

The page lists the conditions of each rule. It groups them by the module whose
data they read: Board, Forge, Bridge or Inbox. A rule whose condition no longer exists
on this instance gets an amber tag that names the condition.

### Named facts

A template can name a condition that several rules or markers use. The top-level
`facts:` map holds the named conditions. Each key is a name of lowercase letters,
digits and hyphens, and it starts with a letter. Each value is a condition
expression, written as in a rule.

```yaml
facts:
    pr-reviewable:
        all:
            - pr.open: {}
            - not: { pr.draft: {} }
    pr-ready-for-approval:
        all:
            - fact.pr-reviewable: {}
            - pr.checks_passed: {}
```

A rule, a marker or another fact uses a named fact as `fact.<name>: {}`. A named
fact takes no parameters. A condition inside a fact takes the parameters that its
own class requires.

The template is refused when a fact names an unknown fact, when a fact takes
parameters, or when facts refer to each other in a loop. The message names the
path, such as `facts.a: loop a -> b -> a`. A fact that no rule uses is still
checked.

A named fact does not change what a condition means. A rule that uses `fact.x`
behaves as if it held the condition of `x` in place. The **Workflow** page lists
the conditions of the fact, each with the name of the fact after it.

## Markers

A marker tells a reader what a card needs now. The template defines every marker.
The board, the list, the card page and the MCP tools show the markers that the
engine stores on the card.

A marker has these parts:

- A key, such as `checks-failed`. The MCP tools call it the `code`.
- A kind. See below.
- A label. This is the reason that a reader sees.
- A remedy. This is one line that says what clears the marker.
- A link, optional. The value `agents` shows a link to the Agents page under the remedy. The value `repositories` shows a link to the Repositories tab of the project settings.
- Slots, optional. They name the slots where the marker applies.
- A condition. The marker is on while the condition is true.

The label and the remedy can name a value of the card, such as the number of the
pull request or the title of a document.

The kind is `stuck`, `needs-you`, `working`, `waiting` or `chip`. A `chip` is a
note and not a state. **Unmanaged** is the only chip that the shipped templates
declare.

A card shows one state mark. The mark is the first marker of the first kind that
matches, in the order `stuck`, `needs-you`, `working`, `waiting`. Inside one
kind, the marker that comes first in the template wins. The tooltip of the mark
shows that marker alone. The card page lists the other markers that ask you to
act, chips included. A card with only a chip shows a neutral mark.

A marker with no slots applies in every column that is not terminal. A marker
with slots applies only while the card sits in a column that links to one of
them. A card in a terminal column has no markers. A card in Next with no blocker
and no parent has no marker, because no marker names the Next slot.

A fallback marker counts only when no other marker of a state kind matches.
`nothing-happened` and `pull-request-idle` are fallbacks. They show **Stuck**
when a card has had no history for longer than the **Stuck delay in minutes**
setting of the board, and nothing else explains the quiet.

The shipped markers are:

| Key | Kind | Shows when |
|---|---|---|
| `paused` | Stuck | The card has an active pause |
| `run-stopped` | Stuck | The last worker run failed or had no result, no work is under way, and no retry is due |
| `run-needs-you` | Stuck | The last worker run blocked, gave up or waits for a person, and no question of its own is open |
| `checks-failed` | Stuck | A pull request has failed checks, no fix is under way, and no retry is due |
| `conflicting` | Stuck | A pull request has a conflict and no fix is under way |
| `ready-not-merged` | Stuck | A pull request stayed ready to merge for the stuck delay and no merge is under way |
| `pull-request-idle` | Stuck, fallback | An open pull request had no card history for the stuck delay |
| `pull-requests-closed` | Stuck | Every pull request linked to a card in Implementation closed with no merge, no worker runs and the card is not held. A reference link does not count |
| `nothing-happened` | Stuck, fallback | A card in Product design, Tech design, Implementation or In review had no card history for the stuck delay, no child has an open worker run, and no child history is newer, and no open child shows a working or waiting marker |
| `document-in-review` | Needs you | A document of the card waits for your review |
| `waits-for-approval` | Needs you | A pull request waits for your approval |
| `open-question` | Needs you | A blocking inbox question names the card |
| `session-open` | Working | A person has an interactive session open on the card |
| `design-review-running` | Working | A draft design document of the card is in its agent review. The label shows the round, such as round 2 of 6 |
| `run-open` | Working | A worker runs on the card |
| `no-bridge-online` | Waiting | Work waits, and no online bridge could take it. The card page links to the Agents page |
| `no-capable-bridge` | Needs you | Work waits, and an online bridge exists, but none can run this kind of work. The card page links to the Agents page |
| `bridge-paused` | Waiting | Work waits, and the only online bridges that could take it are paused. The card page links to the Agents page |
| `work-requested` | Waiting | Work waits for a bridge to take it, and a bridge could take it |
| `retry-scheduled` | Waiting | A rule of the card failed and waits for the delay before its retry. The label shows the time the retry is due |
| `forge-request-pending` | Working | Loupe asked the forge to change a pull request and the forge did not answer |
| `held-by-blocker` | Waiting | A card that blocks this card is still open |
| `repository-misfit` | Needs you | A rule of the card wants to ask for work, and the project holds new work because a repository has a blocking gap. The remedy line links to the Repositories tab |
| `waits-for-parent-card` | Waiting | A pull request waits for the pull request of its parent card |
| `waits-for-parent-branch` | Waiting | A pull request waits for a branch that no card owns |
| `waiting-on-checks` | Waiting | The checks of a pull request run |
| `child-stuck` | Stuck | An open child of the epic shows a stuck marker. The label names the child |
| `child-needs-you` | Needs you | An open child of the epic shows a needs-you marker. The label names the child |
| `child-working` | Working | An open child of the epic shows a working marker. The label names the child |
| `child-waiting` | Waiting | An open child of the epic shows a waiting marker. The label names the child |
| `unmanaged` | Chip | A person or an agent made the card unmanaged |

An epic shows the state of its children through the four `child-` markers. The
epic then has one state on the lane head, the card face, the card page and in
the MCP tools. When the markers of a child change, the engine checks its epic
again. Each `child-` marker names the child whose marker of that kind began
first, and counts from that time.

The Simple template declares all of these markers except `pull-request-idle`,
`pull-requests-closed`, `nothing-happened`, `document-in-review` and `design-review-running`, because Simple has no slots.

The engine checks the markers of a card on every evaluation. This includes a held
card and a board with the automation off. It stores the result on the card, with
the time each marker began. A marker that depends on the clock, such as the stuck
delay, also stores the time it next changes. A task checks the card again at that
time. An event that changes a card queues a new check on the `workflow` queue,
which the worker reads before the shared `async` queue.

After a release, the markers of existing cards fill in within a minute or two.
Until then a card can show no mark.

The **Workflow** page lists the markers in template order. Each row shows the
label, the kind, the slots and the condition. The page is read-only.


A marker reads the same conditions as a rule. These conditions suit markers:

- `card.paused`: the card has an active pause.
- `card.value`: the card holds a value for the value key in `key`. With `equals`, the held value must equal it. A key with no valid value is unset, so `equals: true` is false. The template must declare the key in its `values` list, or it fails to load.
- `card.document.in_review` with a `tag`: a linked document with that tag is in
  review.
- `card.quiet_for`: nothing happened on the card for the `delay`, in minutes.
  With `children: true`, the newest history event of any child counts too, and
  the condition is false while a child has an open worker run.
- `card.rule.held_by_blocker`: an open blocker alone holds a move rule of the
  card.
- `card.rule.retry_scheduled`: a refused rule of the card waits for the delay
  before its retry. The condition turns false at the due time.
- `card.run.stopped`: the latest run of the card ended with a warning, and the
  card did not move after it ended.
- `card.run.needs_person`: the newest run of the card blocked, gave up or waits
  for a person, and the card did not move after the run ended.
- `card.run.asking`: the newest run of the card waits on a question of its own,
  or that question closed less than 10 minutes ago.
- `card.document.agent_review_open`: an agent asked you to review a document in
  review of the card, and that review item is open.
- `pr.checks_on_head`: the checks ran on the head commit of the pull request.
- `pr.can_merge`: the forge reads the pull request as mergeable, behind or
  blocked.
- `pr.base_is_default`: the pull request targets the default branch.
- `pr.approval_covers_head` with a `min`: at least that many approvals of people
  cover the head commit. Set `by: person` to say so in the rule. Loupe no longer
  posts a review, so only people count.
- `pr.approval_check_passed`: Loupe posted the check `Loupe / Approval` with the
  conclusion success on the head commit, and has not revoked it.
- `pr.can_post_check`: Loupe can post a check run on the pull request, because its
  repository has an active GitHub App installation.
- `pr.merge_blocked`: GitHub reads the merge state of the pull request as blocked.
- `card.run.session_open`: a person has an interactive session open on the card.
- `design_review.running` with a `tag` and `rounds`: a draft document with that
  tag has an agent review that is not finished. The label of the marker shows
  the round and the `rounds` limit.
- `card.held`: a person held the agents of the card.
- `card.run.work_open`: a work request of the card is open, which means no
  bridge claimed it yet. A claimed request does not count, so the condition
  turns false when a bridge takes the work. The "Work waits for a bridge to
  take it" marker reads it.
- `card.run.work_no_taker`: a work request of the card waits, and no bridge that
  follows the project, reports the kind of work and was seen in the last three
  heartbeat intervals could take it. The marker counts from the later of the
  start of the wait and the moment the last such bridge went quiet.
- `card.run.work_no_capable_taker`: a work request of the card waits, a bridge
  that follows the project is online, and none of those bridges can run the
  work. A bridge runs a kind of work when its rule file maps the kind, or when
  it reports `app-prompts` and the request ships a prompt.
- `card.run.work_taker_paused`: a work request of the card waits, and every
  online bridge that could take it is paused. A bridge counts as paused while
  you paused it, or while its last heartbeat said so.
- `card.inbox.open_question`: an open inbox question names the card. The
  optional `blocking` is `true` to match only a blocking ask, or `false` to
  match only a non-blocking one, such as a to-do. With no `blocking`, any open
  ask matches. Lifecycle and Simple set `blocking: true`, so a non-blocking
  to-do does not show "Needs you".
- `card.children.marked` with a `kind`: an open child of the card shows a
  marker of that kind. The `kind` is `stuck`, `needs-you`, `working` or
  `waiting`. A chip does not count.
- `card.run.waits_on_answer`: a session stopped on a run of the card to wait
  for the answer to a blocking question, and that run is the newest run of the
  session. The question need not name the card. Lifecycle uses it with `not:` in
  its four fix rules, so no second fix starts while a session of the card waits
  for an answer.
- `pr.checks_pending`: the pull request is open, and its checks run.
- `pr.forge_request_pending`: Loupe asked the forge to merge, sync or change
  the base of the pull request, and the forge did not answer yet. A request
  expires after 10 minutes with no answer.
- `pr.stack_parent`: the pull request is ready to merge with nothing pending,
  and waits for the branch it is stacked on. The optional `parent` is `known`
  when a card links the open parent pull request, or `unknown` when only the
  branch is known. With no `parent`, both match.
- `pr.ready_for`: the pull request is not stacked, and stayed ready to merge
  with nothing pending for the `delay`, in minutes.

The `delay` of `card.quiet_for` and of `pr.ready_for` is optional. With no
`delay`, the condition uses the **Stuck delay in minutes** setting of the board.

Markers also read conditions that rules already use: `pr.open`,
`pr.checks_failed`, `pr.conflicting`, `card.run.work_active` and
`card.run.worker_active`.

Both run conditions take an optional `kind`. They also take an optional `origin`,
whose only value is `rule`. With `origin: rule`, work that a person started, such
as a "Send an agent" action on an inbox item, does not count. With no `origin`,
all work counts.

### Markers that open inbox items

The top-level `inbox:` list names the markers that open an item in the inbox.
Each entry is a marker key. The Lifecycle template lists `waits-for-approval`,
`document-in-review`, `run-needs-you` and `paused`. The Simple template lists
`waits-for-approval`, `run-needs-you` and `paused`.

```yaml
inbox: [waits-for-approval, document-in-review, run-needs-you, paused]
```

A listed marker opens one wait for each thing that makes it true. The thing is a
pull request, a document version, a run or a pause. Two pull requests that wait
for approval give two waits in one inbox item. The card has one item. A dismiss
hides one wait, and the wait comes back only for a new thing.

A marker about a document or a pull request makes the item a Review while its
wait is open. Any other open wait leaves the item Waiting.

A marker that the list does not name opens no item. The
[inbox page](inbox.md#automatic-items) describes the items.

The template is refused when `inbox:` names an unknown marker or names a marker
twice. A marker with no pull request, document, run or pause, called a
card-level marker, can be listed. It opens one inbox item for each spell of the
marker on the card, and the item ends when the marker turns off.

## Slots and columns

A slot links to a column by the column's id. A rename of the column keeps the
link, so the rules keep working. A deleted column leaves its slot empty, and a
card that needs that slot pauses with "workflow slot missing".

The Backlog and the terminal columns are not slots. A rule names them as
`@backlog` and `@terminal`.

A slot can name `stageWork`, the kind of work that does the stage of the slot.
The kind must be the kind that a rule of the same slot requests. Lifecycle sets
it for Tech design and for Implementation. The inbox action **Send an agent**
reads it. A card in a column with no `stageWork` gets no such action.

A slot can name a `tone`, the colour of its column when a new project gets the
template. The value is one of the label tones, such as `lime` or `sky`. A slot
with no `tone` gets a neutral column.

## Managed cards

A card is managed by default. A managed card follows the template, and a person
may make only the moves that the template lists. Any other move offers to make
the card unmanaged first. An unmanaged card is outside the workflow: the
workflow makes no move and asks for no work on it.
[The board](board.md#managed-and-unmanaged-cards) describes both states, and
the Workflow panel of the card page.

A move in the template can name who may make it. A move with
`by: parent-run` is open to an open worker run of the parent epic, and not
to a person. The run may be of any work kind, such as a breakdown or a fix.
An interactive run does not count. Lifecycle names no such move. A move with
no `by` is open to anyone. The **Who** column of the manual moves on the Workflow settings page shows who may
make each move.

## Pauses and retries

The workflow pauses a card when a rule asks for it, and when work keeps failing.
A refused action fires again as soon as a fact that its rule reads changes, such
as a new head commit. Otherwise it retries after 10 minutes, after 1 hour and
after 6 hours. After the last retry the card pauses, and the owner's inbox gets
an item that names the reason.

A bridge can settle a work request as refused, for example when its worker run
fails. In Lifecycle, the workflow then retries the work up to three times,
after 2, 3 and 5 minutes, when the refusal code is `failed` or `timeout`. A
retry never counts toward the work limit of a fix rule. When the third retry
also fails, the workflow starts a repair, as the next paragraphs say. Any other
refusal code, such as `unfinished` or `work-remains`, pauses the card at once
with "the worker stopped and needs a person". The board marks a card whose
latest worker run failed or ended with no result, until a later run ends in
another state.

A pause after a refused or expired request ends by itself when a fact that its
rule reads changes after the refusal, such as a new head commit. A "needs a
person" stop is the exception, as a later paragraph says. The work then
starts again. The end of the card's own request does not count as a change, so
a worker that blocks does not start a new run on the same card. A retry after a
refusal also waits for its delay. During the delay the card shows the
`retry-scheduled` marker, which waits, and not `run-stopped` or
`checks-failed`. When the retries and the repair run out, the card pauses and
shows `paused`.

A request can end while its rule still holds for a new reason. An example is a
fix run that ends after the pull request became conflicting. The workflow then
asks for one more fix at once. A part of the rule's condition that holds now, and
did not hold when the request opened, counts as a new reason. A request that ends
with the same reasons it started with asks for nothing more.

When the retries run out, the workflow opens one `repair` work request for the
card. The request names the failed rule in `{ruleId}`, and the last refusal
code in `{reason}`. A repair worker reads the failed run, fixes the cause, and
reports. When the repair ends done, the failed rule gets one last try. When the
repair fails, or the last try fails too, the card pauses with "too many
attempts were refused". A failed repair records the code `repair-failed`.

Each escalation starts one repair at most. A repair request is never retried
and never repaired. A release of the pause starts a new escalation, so the
next failure can start one more repair. A bridge with no `repair` entry takes
no repair, and the repair request waits until a bridge takes it. [The repair
entry](../extending/cli-bridge.md#repair-entry) shows one.

A resumed run ends the pause of its card. When a person resumes a run, or a
closed [inbox](inbox.md) ask resumes it, the workflow releases the pause as soon
as the resumed run reports to Loupe. This holds for every pause a person can
release, and not for a pause that a rule sets with its own release condition.
The card then asks for no second worker. A resumed run that fails earns the
retries of a fresh budget, and a resumed run that ends as blocked pauses the
card again at once.

A "needs a person" stop does not pause a card when a resumed run of the same
kind of work, from any rule of the card, reported after the stop. That run also
ends such a pause. A stop with any other code pauses the card as before.

A "needs a person" stop waits for a person. New facts do not end its pause,
also when they change what its rule reads. A new head commit, new checks or a
new failed agent review leave the card paused, because they do not answer the
worker. These events end the pause:

- A person releases it with **Retry now**, or resumes the stopped run.
- A resumed run of the same kind of work reports, as the paragraph above says.
- The card leaves the slot of the rule, or the workflow template removes the rule.
- The rule moves to another open pull request.
- The pull request of the rule closes or merges.

While the card is paused, no rule runs on it. So a review of a new head waits
too, until a person retries the card. Other stop codes, such as `blocked`, keep
the release on changed facts that the paragraph on refused requests describes.

The template sets this behaviour in its `onWorkFailed` block:

```yaml
onWorkFailed:
    retryOn: [failed, timeout, still-true, mcp-unreachable]
    retries: 3
    backoffMinutes: [2, 3, 5]
    repair: { kind: repair }
```

`retryOn` lists the refusal codes that retry, and `retries` counts the retries
before the repair. `backoffMinutes` gives the wait before each retry. `repair`
names the kind of the repair request. A template with no `repair` key pauses
the card when the retries run out. A template with no `onWorkFailed` block
neither retries nor pauses after a refused request.

A worker that cannot reach the Loupe tools at its start reports the code
`mcp-unreachable` with the status `unfinished`. It has changed nothing, so
`retryOn` lists the code and the workflow retries the run. The retry resumes the
same session. An `unfinished` run opens no inbox item, so the owner hears of it
only when the retries and the repair run out and the card pauses.

A request can also end with no result while its rule still holds. A `request`
action that sets `stillTrue: retry` counts such an end as a failed try with the
code `still-true`. The rule then asks again after the next wait, and `retryOn`
must list `still-true`, or the first such end pauses the card. The Lifecycle
review rules set it, so a review run that ends with no review, such as one that
saw a new head, asks for a review of the current head again.

A work request that no bridge takes does not expire. It waits until a bridge
takes it or until its rule cancels it, for example when the card leaves the
column of the rule. The card shows "No bridge is online to take this work." as
Waiting when no online bridge could take the work, and "A bridge is online, but
none can run this work." as Needs you when no online bridge can run the kind. Work that
waits 10 minutes with no online bridge opens the notice **No bridge can take
work**, as [the inbox guide](inbox.md#notices) says.

A person ends a pause with **Retry now** in the Workflow panel of the card. The
button shows for a pause after too many retries, after the work limit, or after
the worker stopped. It needs the permission to manage the project. A
release is refused on an unmanaged card. It is also refused on a pause that a
rule made, because that pause ends only on its own release condition. After a
release the paused rule runs again with a fresh budget of retries and work. The
card history records the pause and the release, with the person who released
it. A pause that the workflow ends by itself shows in the history too, with
Loupe as the actor and the reason. An agent ends a pause with the `card_pause_release` MCP tool.

### Pause for a blocking repository gap

The Repositories check of a project can find a blocking gap, which is a setting of
a repository that contradicts the workflow, such as a review rule that the Loupe
App cannot satisfy. While any repository of the project has a blocking gap, the
project holds all new work:

- A `request` rule of a card gets the outcome `held`. It writes no attempt, no
  backoff and no pause. The card shows the `repository-misfit` marker, and the rule asks again by
  itself when the hold ends.
- A bridge cannot claim a request of the project. The claim is refused with
  `project_held`, and the request does not show in the offers of a bridge.
- When the hold starts, Loupe withdraws each open card request of the project, so
  the hold leaves no stale request behind. A request about another
  subject, such as an analysis, stays open, and it gets a new timeout when the hold ends.
- Work that a bridge already claimed, and a run that is under way, finishes.
- Rules that start no worker still run: moves, writes to GitHub and merges.
  A request for teardown or for the epic preview is held like any other.

The hold follows the stored result of the check. A failed read keeps the last
certain result, so a failed read neither starts nor ends the hold. A removed
repository leaves the result at once. When no repository has a blocking gap
any more, after a check or after a removal, the hold ends. Each held rule then asks
for its work again.

## Writes to GitHub

A rule can write to a pull request: merge it, update its branch, change its
base, open an epic pull request, switch it between draft and ready, close it,
or post a comment on it. The rule
alone decides each write, and the write happens when the rule fires. When the
project has no GitHub App installation, the rule asks a bridge for the same work
instead. See [Automation](board.md#automation).

The `merge` write takes the parameter `methods`, a list of merge methods in order of preference. The Lifecycle
template lists `squash`, `merge` and `rebase`. Loupe reads the merge switches of the repository and the merge
methods that the rules of the base branch allow. It merges with the first method of the list that both allow. When
the repository and its rules allow none of them, the write refuses with the cause `no_merge_method`, and a person
changes the repository settings or the ruleset. A project that wants a different order edits the list in the template.

The `merge-main-into-epic` write acts on a child in Next whose blocker outside
its epic is done. It merges the default branch into the epic branch in each
repository where such a blocker merged a pull request, then records on the
epic which blockers the merge covered. A merge conflict refuses the write, and
a person resolves the conflict. A project with no GitHub App installation gets
work of kind `sync-epic` instead. When that work ends done, the epic records
the blockers that were done when the work opened. A blocker that finishes
again later asks for a new merge. That work merges in one repository, so when
the code lives in several repositories and Loupe cannot merge in one of them,
the write refuses, and a person merges by hand.

The write `approval-check` posts the check `Loupe / Approval` as the GitHub App. It needs an
active installation on the repository, and it never asks a bridge for work. It takes the parameter `state`.
A check run belongs to one commit, so a push leaves the new head with no `Loupe / Approval`.
Loupe never posts an incomplete run, because GitHub counts an incomplete run as stale after 14 days.
Loupe stores the run id, the commit and the conclusion on the pull request, and reads that record.

| Write | Does |
|---|---|
| `approval-check` with `state: pass` | Posts `Loupe / Approval` with the conclusion success on the head commit that the rule read, and writes an `auto-approved` row in the history of the card. A head that moved since the rule read it ends the write with no post, and the next evaluation decides again |
| `approval-check` with `state: revoke` | Changes the stored run on the current head to the conclusion failure, with the title "A person must approve", and writes an `approval-dismissed` row in the history of the card. It does nothing when the run is not a success on the current head |

Two further writes come from the [rules the app adds](#rules-the-app-adds). They
act on a live-review verdict, and they never ask a bridge for work:

| Write | Does |
|---|---|
| `post-review` | Posts each unsent verdict as a review on the open GitHub pull requests that the reviewer picked. The review goes out under the reviewer's own GitHub account |
| `site-review-check` | Keeps a check named `Loupe / Live review` on each open GitHub pull request of the card. The check counts the notes of every card that links the pull request. The check goes out as the GitHub App |

A review that the reviewer sends on their own pull request becomes a comment,
because GitHub refuses a review from the author of a pull request.

The `review-ready` write marks the open pull request that the rule acts on as
ready for review. It skips a closed or merged pull request. It has no fallback.

The `agent-review-check` write posts the reviews that a `review` worker sent
with the `agent_review_submit` MCP tool. A review of an open GitHub pull request
of the card gets a new check named `Loupe / Agent review` on the commit it
reviewed. The check fails when a finding has a severity that the template
counts as failing. Each finding shows as a note on its lines. The write has no
fallback. A project with no GitHub App installation gets no check, and its
reviews stay stored.

The `comment` write with `comment: agent-review` posts one comment on the pull
request for a review that has findings. The comment holds the verdict, the
summary of the agent, up to 20 finding titles, and links to the check and to the
card. It leaves out the text of each finding. The comment goes out as the GitHub
App, and it has no fallback. See [Agent review](#agent-review).

## Outcomes and metrics

A template declares the outcomes of its moves and the metrics about its work.
Loupe checks both lists when it loads a template. The analytics pages and the
experiment tables read the metrics from the stored data in a later release.

### Outcomes

The top-level `outcomes:` list names each outcome that a `move` may set. An entry
is a key, or a map with a `key` and `delivered: true`. At most one outcome is
delivered. A key is lowercase words joined with hyphens, at most 64 characters.

```yaml
outcomes:
    - { key: merged, delivered: true }
    - dropped
```

Both shipped templates declare `merged` as the delivered outcome.

### Metrics

The top-level `metrics:` list holds the metrics. Each metric has a `key`, a
`label` and a `description`. The label and the description are plain English text
and show as written. The label is at most 80 characters, and the description is
at most 300. A key follows the rule of an outcome key. It cannot be the key of a
metric that Loupe computes itself, such as `cost` or `runs`. Each metric has
exactly one type: `count`, `rate` or `duration`.

```yaml
metrics:
    - key: design-rounds
      label: Design review rounds
      description: The design revise rounds a finished card needed.
      count: { role: design-revise }
    - key: needed-fix
      label: Cards that needed a fix
      description: The share of finished cards with at least one fix round.
      rate: { any: { role: fix } }
    - key: hours-waiting-for-approval
      label: Hours waiting for approval
      description: The hours from the last agent review pass to ready to merge.
      duration: { from: { last: agent-review-passed }, to: ready-to-merge }
```

A **count** counts the work requests of a card. Its filter takes `role`, `kind`
and `reason`, alone or mixed. A count can add `splitBy` with one of these three
names, to show one row for each value.

A **rate** is the share of finished cards that match. `rate: { outcome: <key> }`
matches a card when any of its moves set the outcome. `rate: { any: <filter> }`
matches a card when the filter matches at least one of its work requests.

A **duration** is the time from a start event to an end event. The events are
`pr-opened`, `agent-review-passed`, `ready-to-merge` and `pr-merged`. The two
slot events are `{ arrived: <slot> }` and `{ left: <slot> }`. The slot can be a
slot key of the template, `@backlog` or `@terminal`. A duration runs from the
first start to the last end after it. On `from`, `{ last: <event> }` starts at
the last time the event happened. A card with a start and no end after it has
no value.

The Workflow page lists the metrics of the template in a **Metrics** section, after
the card markers. Each row shows the label and the description, the key, the type
and a sentence that says what the metric reads. A template that declares no
metric shows a line that says so.

Loupe refuses a template that names a value which none of its rules can produce.
The error lists the values it knows. A role, a kind and a reason must come from
a `request` rule of the template. A filter that mixes fields must match one
`request` rule as a whole, so `{ role: fix, kind: review }` needs a single rule
with both. The outcome of a rate must come from a `move`
of the template. A `request` rule that sets no reason makes the fix reasons
`conflict`, `checks-failed` and `changes-requested` known, and `unknown` too. A
template whose `request` rules all set a reason knows only those reasons. The event `agent-review-passed` needs a `request` rule of the kind
`review`. A slot event needs a slot that the template declares. The Simple
template has no `review` request and no slots, so it cannot use these events.

## The Lifecycle template

Lifecycle has the slots Next, Product design, Tech design, Implementation and In
review. A person can move a card from the Backlog to Next, Product design or
Tech design, and from Next back to the Backlog or on to a design slot. A person
can also move a card from the Backlog or from Next straight to the terminal
column, for example when the work is no longer needed. A person can move a card
from Implementation back to Tech design, for example when the card has no tech
design yet. A card whose tech design is approved goes
back to Implementation at once when it has no open blocker.
When you withdraw the approval of a design, the card goes back to that design. See [Undoing a design](#undoing-a-design).

| Slot | The workflow |
|---|---|
| Product design | Asks for an interactive product design session when the card has no product document and no interactive session is open. A session that opens withdraws a waiting request that no bridge has taken. It also asks for a revision when the product document gets changes requested. When every document with the tag `product-design` is approved, the card moves to Tech design. The session request also carries two [discovery](workshop.md#run-discovery) checks, for a design system and for a Design System artifact that mirrors it. When the project settings file names a design system, the product document lists the components that the change reuses or changes. A draft product document gets an [agent review](#agent-review-of-a-design) before the app publishes it to you. A clean design [approves itself](#automatic-approval-of-a-design) in the pass that publishes it |
| Tech design | Asks for the tech design when the card has no design document, and for a revision when it gets changes requested. When every document with the tag `tech-design` is approved, the card moves to Implementation once it has no open blocker. A card whose blocker is done gets one recheck first: an agent reads the approved design against the code on `main`, and the card moves on when the design still holds. See [Recheck of a design](#recheck-of-a-design). A draft tech design, with a draft deploy notes document, gets an [agent review](#agent-review-of-a-design) before the app publishes it to you. A clean tech design [approves itself](#automatic-approval-of-a-design) in the pass that publishes it. When the card value `tech-design-interactive` is on, the workflow asks for an interactive tech design session in place of the agent. See [Interactive tech design](#interactive-tech-design) |
| Implementation | Asks for the implementation, or for a breakdown of an epic into children. An epic gets its breakdown when it enters, with or without children. A pull request that is open, not a draft, whose required checks passed and whose [agent review](#agent-review) passed moves the card to In review, once no open pull request of the card is a draft. Failed checks, a conflict or a request for changes ask for a fix, 3 rounds at most. A fix rule waits while a session of the card waits for the answer to a blocking question. A card with no pull request whose implementation run finished with the reason `nothing-to-build` or `delivered-without-code` moves to the terminal column. See [Finishing without code](#finishing-without-code) |
| In review | Asks for a fix as in Implementation. A pull request that turns back into a draft moves the card to Implementation. A stacked pull request whose parent merged gets a new base. An approved pull request that is behind gets its branch updated. A ready pull request merges once its agent review passed |

### Interactive tech design

The card value `tech-design-interactive` is the switch "Interactive session" under Tech design. It starts off.
With the switch on, a card in Tech design with no design document gets three rules:

| Rule | Does |
|---|---|
| `tech-design-session` | Asks for work of kind `tech-design-session` when no interactive session is open and no tech design work or worker is active. The request needs a bridge that can open an interactive session. It leaves a request that a bridge took, and withdraws a waiting request when a session opens or the switch turns off |
| `tech-design-handover` | Runs `stop-work` when the switch turns on while an agent that a rule started writes the design. It spares an agent that you sent from an inbox item |
| `tech-design-write` | Asks for the agent only when the switch is off and no session is open. A session that opens withdraws a waiting agent request |

The rule `tech-design-review` waits while an interactive session is open, as the Product design rule does.
When a session closes with no design, the workflow asks for a session again if the switch is on, and for the agent if it is off.

### Recheck of a design

A tech design that waited for a blocker describes code that the blocker had not
merged yet. When the blocker is done, the code can differ from what the design
assumed, for example a renamed class or a dropped field.

The `tech-design-recheck` rule asks for a recheck when all of these hold:

1. The tech design is approved and has no changes requested.
2. No blocker of the card is open.
3. At least one blocker of the card sits in a terminal column.
4. The latest work request of the card is not a `tech-design-recheck` that ended
   with the reason `done`.

The agent reads the design and the code on `main`. When every fact
still holds, it ends with `tech design current`, and the card moves to
Implementation. When a fact no longer holds, it revises the design. The
revision puts the design back in review, and the card moves on after the owner
approves it again. The latest work request is still the recheck, so the
approval does not ask for a second recheck. A later `tech-design-revise` run
replaces the latest work request, and the next approval asks for one more
recheck.

A card with no blocker link never gets a recheck. A card whose blocker link
points to a card that finished before the design was approved gets one recheck.

A design document in review, a draft and a revision keep the slot from asking
for a second design. Archive the document to ask for a new one.

An upgrade retags the existing documents. The tag `product` becomes
`product-design`, and the tag `design` becomes `tech-design`.

### Finishing without code

An implementation worker can find that a card has no code to build. An answered
decision of the tech design can remove all the work, or the card can ask only
for a document, such as a measurement. The worker then ends its run as finished,
with one of two reason codes:

| Reason code | When the worker uses it |
|---|---|
| `nothing-to-build` | An answered decision removed all the work of the card |
| `delivered-without-code` | The card asked only for a document, and the worker linked it |

The bridge settles the `implement` request as done with that reason. The rule
with the same name then moves the card to the terminal column. The rule waits
while the card has a work link, while it is an epic, and while work runs on
it. The card history says "Via the nothing-to-build workflow rule" or "Via the
delivered-without-code workflow rule". The worker also adds one line to the card
body that names the decision or the document. A parent epic counts the card as
finished, as it counts any card in a terminal column.

### Reference links

A link to a pull request that was merged or closed before you linked it is a
reference. Use a reference to record where a bug came from. The workflow does
not count a reference as a work link. The condition `card.pr.linked`, the
`merged` rule and every other pull request condition ignore it, and an
implement request names no pull request for it. The card page still shows the
link. A card that has only reference links gets implement work when it reaches
Implementation, and it does not move to Done. A link on a card that existed
before this rule is judged against the creation time of the card. A closed pull
request counts as work until Loupe has read its close time.

In a workflow file, the condition `card.run.finished_with` with a `kind` and a
`reason` is true when the latest work request of the card is done, of
that kind, with that reason. A bridge older than this release sends no reason
with a done request, so the rules never fire for its runs.

### Agent review

A separate agent reviews each open pull request of a card while it is still a
draft. The review belongs to one head commit. An epic gets its review only
when all its children are finished, because the review compares the pull
request with the whole tech design. Until then, the epic gets no review and no
fix round for one. The rules of the template turn the review on. A template with none of the rules below asks for no review, and its
cards follow the other rules.

When the check of a review with findings lands, the app rule
`comment-agent-review` queues one comment on the pull request. The comment sums
up the review for a person who reads the conversation tab. Loupe posts it once
for each review, so a carried review, a check that an override posts again, and
a retry post no second comment. A review that landed more than one hour before
the rule ran gets no comment. When GitHub refuses the comment because the App
lacks the permission, Loupe does not try again. A rate limit, a timeout or a
server error is retried 3 times.

A review run can end with no review, for example when the head moved after the
request. The review rules then count the run as a failed try and ask again after
2, 3 and 5 minutes. After the retries, a repair starts, and then the card
pauses with an inbox item.

An approval of the pull request on the forge can override a failed review. The
approval must cover the current head, as the merge rule asks, and it must come
after Loupe stored the review. The workflow then treats the review as accepted.
The card moves to In review, a draft is marked ready, and the pull request
merges once its other conditions hold. No fix round starts for the findings. When
the approval is dismissed, or someone requests changes, the override ends and the
failed review blocks the card again. An approval in the Loupe inbox does not
override, because the inbox does not post it to the forge. An epic child needs an
approval to override, because it merges with no approval otherwise. A fix round
that already runs when the approval arrives finishes. A push from it makes a new
head, which needs its own review. A fix round that ends with no push does not
ask for a new review of an overridden head.

A new head whose change is identical to a reviewed head carries the review. A
clean merge of the base is the usual case. The check on the new head says that
the review was carried, and from which commit. It keeps the line notes only when
the changed lines keep their line numbers. Otherwise the summary lists the
findings. A different change gets a full review. So does a pull request of 300
files or more, a forge other than GitHub, or a head that Loupe could not read.
A changed file that GitHub shows with no patch, such as a binary or a very
large file, also gets a full review. A file that a pull request only renames
does not.
A carried review does not judge the change against the new base. CI catches the
mechanical breaks.

The review checks the code, and it also checks the whole pull request against
the designs that the card links. The reviewer reads the product document, the
tech design, the answers to their decisions, and the build notes. A finding of the
category `spec` reports a gap between the pull request and a design: a
requirement that nothing builds, a decision that the change makes differently,
a behaviour that no requirement asks for, or a deliverable that a design names
and the diff lacks, such as a documentation page. The changelog entry has its
own CI check, so the review leaves it out. A requirement that nothing builds has
no file and no line, so it shows in the summary of the check and has no line
note. The check lists the `spec` findings first.

A finding with a clear rule or root problem opens its body with "Rule:" and the
rule. It ends with "Also at:" and every other place in the change where the rule
applies. The reviewer gives the most attention to the commits of earlier fix
rounds. Each fix commit carries a `Loupe-Fix-Round` trailer, so the reviewer can
find it.

The fix worker fixes the rule at every place in the pull request, not only the
place that the finding names. It adds a test that checks the rule. Before it
pushes, it reviews its own fix with the same checklist as the reviewer.

The fix worker fixes a failed `spec` finding as any other finding. When the
implementer chose on purpose to depart from a design, the build notes record
the departure under a "Departures" section, with the reason. The review then
shows it as a nit, which never fails the review, and the fix worker leaves it
alone. A departure with no record fails the review.

The fix worker answers a finding that needs evidence instead of code, and a
finding that it judges wrong, in the build notes. It writes one subsection per
finding under a "Review answers" section, named after the finding. The
subsection opens with the result in one sentence, then says what the finding
asked, what the worker did, and the raw evidence. The worker adds one line to
the pull request body that links the section. The reviewer reads each answer as
a claim, as it reads a departure. Evidence that is missing, hard to follow, or
that states no result is an `important` finding.

The reviewer marks a `spec` finding with `needsPerson` when a worker cannot
settle it from the design and the code alone. The design may be unclear,
contradict itself, or look wrong against the code. The change may also depart
from the design with no departure on file. The check labels such a finding
"waits for the owner", and the review fails on it. The fix worker fixes the
other findings first. It then writes a Loupe document with the tag
`spec-review`, linked to the card, and asks you one question in your inbox. The
worker pushes only after you answer. A finding where the code simply does not
build what the design says goes to the fix worker alone. It creates no document
and no inbox item.

Six conditions of the **Agent review** group read the reviews of pull requests:

| Condition | True when |
|---|---|
| `agent_review.due` | the head commit of the pull request that the rule acts on has no review, own or carried. It is also true when the newest review failed and a fix round for the agent review ended done after that review began, so the same commit gets a new review. It is false while an approval overrides the review |
| `agent_review.failed` | the newest review of the head commit of the pull request that the rule acts on failed, own or carried. It is false once a fix round for the agent review has ended done after that review began, until the next review lands. It is also false while an approval overrides the review |
| `agent_review.passed` | every open pull request has a head commit and a review of it that passed. It is false when no open pull request has a head commit |
| `agent_review.overridden` | every open pull request has a head commit and a review of it that passed or that an approval overrides, and an approval overrides at least one |
| `agent_review.unposted` | a stored review of the card has no check on the forge yet, on its own head or on a head it was carried to |
| `agent_review.uncommented` | the newest review of an open pull request has at least one finding, its first check on its own head landed in the last hour, and it has no summary comment yet. A clean review, a carried review and a review older than one hour never count |

A review fails when it has a finding of a severity that the template counts as
failing. The `agentReviewFailingSeverities` value of the template lists those
severities, from `important`, `nit` and `pre-existing`. A template with no value
counts `important` alone, and Lifecycle sets `[important]`. The review of an
older head commit does not count.

To turn the review off, remove the rules `agent-review`,
`agent-review-in-review`, `fix-agent-review`, `fix-agent-review-in-review` and
`review-ready` from the template. Also remove `agent_review.passed` and
`agent_review.overridden` from the rules that move or merge a card.

Lifecycle uses the conditions in these rules:

| Rule | Slot | Does |
|---|---|---|
| `agent-review` and `agent-review-in-review` | Implementation and In review | When `agent_review.due` holds, asks for work of kind `review`. The rule waits while work of kind `implement`, `fix`, `sync` or `rebase-stacked` is active on the card, because that worker can still push a new head |
| `fix-agent-review` and `fix-agent-review-in-review` | Implementation and In review | When `agent_review.failed` holds, asks for a fix with the reason `agent-review`. The limit is 10 rounds. The count starts again when `agent_review.passed` holds |
| `review-ready` | Implementation | Marks a draft pull request ready, when its review passed, its checks passed, it has no conflict and no request for changes. It uses the `review-ready` write |

The limit of 10 is fixed in the template. The rules `reviewable`, `merge-ready` and `merge-ready-epic-child`
also need `agent_review.passed` or `agent_review.overridden`. The app rule `sync-agent-review-check` posts the
check of each stored review, and the app rule `comment-agent-review` posts its
summary comment. See [Rules the app adds](#rules-the-app-adds).

A person who turns a ready pull request back into a draft makes `review-ready`
fire again while the review and the checks pass. The rule `back-to-draft` first
moves the card from In review to Implementation.

A card with a ready and a draft pull request waits in Implementation. The rule
`reviewable` does not move the card to In review while any open pull request of
the card is a draft. `review-ready` marks the draft ready once its review and
checks pass, and `reviewable` then moves the card.

A pull request is ready to merge when it is reviewable, an approval covers its
head, its base is the default branch or the card's epic branch, and it has no
conflict. A child pull request into its epic branch also waits while any
worker run of the epic is open. A merge from the base after the approval keeps
the approval. New commits need a new approval.

The template value `approvalCoversConflictResolution` decides what a merge from
the base keeps. It is `true` in the Lifecycle template, so the approval covers
every merge from the base, also one in which an agent resolved a conflict. When
a project sets it to `false`, the approval covers only a merge that git writes
with no human edit. A merge that resolves a conflict, or changes any other file,
needs a new approval. Loupe tests this from the file lists of the GitHub compare
API, so a merge that touches 300 files or more needs a new approval too. The
value belongs to the copy of the template that the project holds, and a new
release of the template keeps it. A person asks their agent to change it, which
calls the `automation_settings_update` MCP tool with the argument
`approvalCoversConflictResolution`. A change of the value judges the approval
of each open pull request of the project again. The `workflow_get` MCP tool
returns the value.

Some rules act from any slot:

1. A card whose pull requests all finished, with one merged, moves to the
   terminal column once no child is open. A merged epic also waits while any
   worker run of it is open.
2. A card in the Backlog whose pull request reopens moves to Implementation.
3. A child of an epic starts only when it links an approved tech design and its
   epic sits in Implementation. A child in Next moves to Implementation when it
   has a document with the tag `tech-design`, every such document is approved,
   it has no open blocker, and it has no pull request. It also waits until the epic
   branch holds the code of each blocker outside the epic. The rule
   `epic-branch-sync` merges `main` into the epic branch first, and the
   condition `card.parent.epic_branch.synced` is true when that is done. It waits while the epic has work in
   progress. That work is any open worker run of the epic, and any work that the
   workflow requested for the epic and no bridge finished. When the last such
   work ends, the epic evaluates its children again, in any column. A change to
   the work requests of the epic, or to the documents of the child, also
   evaluates the child. An older bridge that sends no run key reports a run only
   after it ends, so its run does not hold the children. In a workflow file, the
   condition `card.parent.document.approved` with a `tag` is true when the parent
   card has a document with that tag and every such document is approved. The condition `card.parent.in_slot`
   with a `slot` is true when the parent card sits in that slot.
4. A child in Next that links no approved tech design asks the owner what to do,
   once the epic has an approved tech design and no work in progress. The rule
   is `unplanned-child`. See
   [Asking about an unplanned child](#asking-about-an-unplanned-child).
5. A new child of an epic moves from the Backlog to Next. The rule is
   `child-to-next`, and it is one of the last two rules of the file. The move
   happens once. A person who moves a child back to the Backlog parks it, and
   the child stays there until a person moves it to Next. A follow-up card
   moves from the Backlog to Next in the same way, when every card it follows
   sits in a terminal column. The rule is `follow-up-to-next`. A follow-up has
   no parent, so its epic never waits for it. A board with the Simple template
   moves no follow-up. Only the `Follow-up.` marker in the Breakdown section of
   the tech design makes an entry a follow-up. The breakdown stops, and creates
   nothing, when an entry reads as work after the epic and has no marker, or
   when a child entry waits for a follow-up.
6. An epic that enters Implementation evaluates its children. The rule is
   `epic-entered-implementation`. A child that waited for its epic starts at
   once when it can.
7. A card that reaches a terminal column asks for a teardown, which removes its
   worktree on the bridge.
8. An epic in In review, whose children are all finished, asks for an epic
   preview refresh when no refresh ended well at the head of its pull request.
   It waits while any other work or worker run is live on the epic. The rule is
   `epic-preview`, with the condition `card.run.done_at_head`. Three failed
   refreshes in a row pause the epic.
9. A fix run that a bridge queues gets one comment on its pull request. The
   rule is `comment-fix-run`, with the condition `card.fix_run.uncommented`
   and the write `comment` with `comment: fix-run`. The comment shows the round
   out of the `limit` of the fix rule. See [Automation](board.md#automation).
10. A pull request whose approval does not cover its newest commit gets one
    comment for each such commit. The rule is `comment-stale-approval`, with
    the condition `card.pr.approval_stale` and the write `comment` with
    `comment: stale-approval`.

An epic follows its children. An epic whose children all finished moves to In
review when it has a pull request, and to the terminal column when it has none.
It moves on only after every work request of the epic ends.
When a child merges into the epic branch and the epic has no open pull request, the
workflow opens the epic pull request. Such an epic never moves straight to the
terminal column. It goes through In review with its epic pull request. With
the open write off, the rule refuses and tries again later. The epic waits in
Implementation until a person turns the write on or links a pull request.
Turning the write on opens the pull request of each waiting epic, and ends the
pause of an epic that ran out of retries. A save that also turns the automation
on does neither, so turn the write on in a separate save.
A new open child moves it back to Implementation. With the epic writes on, the
pull request of an epic is a draft in Implementation, turns ready in In review,
and closes when the epic returns to the Backlog.

### Agent review of a design

An agent reviews a design before the app sends it to you. This covers the
product document and the tech design. The writer leaves the document a draft.
A draft stays out of the inbox, and `document_revise` keeps it a draft. Then
this loop runs:

1. The app asks for work of kind `design-review`. A review worker reads the
   draft and sends its findings with the `document_review_submit` tool.
2. When the review fails, the app asks for a revision of kind
   `product-design-revise` or `tech-design-revise`, with the reason
   `agent-review`. The revise worker changes the draft and answers each
   finding with `document_review_answer`. A finding can be fixed, not changed
   with a reason, or sent to you. The new version gets a new review.
3. The loop ends when a review passes with no finding for you, or when the draft used 6 rounds. A failed review uses
   a round, and so does a passed review with a finding for you. A passed review with a finding for you is
   answered, and the revised version gets a new review. The app then
   publishes the draft with the `publish-documents` action. The document
   reaches your inbox in review.

A review that passes but has a finding for you waits for the revise worker.
The worker writes that finding into the draft as a decision for you, and the
loop ends on the new version. The app publishes the drafts only when
the loop of each draft with the tag ended.

You can skip the loop. When you publish or review the draft yourself, no
draft with the tag remains. The `cancelWhen` condition of the rule is then
true, so Loupe withdraws the live review or revision request. The publish rule waits while any work or worker is
active on the card, so it never cancels the worker that is ending.

Four conditions of the **Agent review** group read the loop of a draft with a
`tag`. Each takes the `tag` and `rounds`, the number of rounds that ends the
loop. A failed review and a passed review with a finding for the owner each use one round:

| Condition | True when |
|---|---|
| `design_review.due` | a draft with the tag has a version that no review read, or a review that a revision answered, and its loop is not finished |
| `design_review.fix_due` | the review of the current version of a draft with the tag failed, has no answers, and asks for a revision |
| `design_review.finished` | each draft with the tag has a finished loop, and at least one such draft exists. A loop is finished when the review of the current version passed with no finding for the owner, or the draft used all its rounds, and the last review failed or passed with a finding for the owner that a fix round answered |
| `design_review.running` | a draft with the tag has a loop that is not finished. The marker `design-review-running` reads it |

The summary below a published design reads its limit from the same setting. It takes the `rounds` of a
`design_review.finished` check that a `publish-documents` rule for the tag requires: the whole condition, or an entry of
an `all:` list. A check under `not:` or `any:` does not count. A document with both design tags takes the smaller limit.
A workflow with no such check gives no limit, so the summary never says that the review did not pass. The summary counts
only the rounds of the card that holds the newest review.

Lifecycle uses them in these rules, for the slots Product design and Tech
design. Each name starts with the slot key, `product-design` or `tech-design`:

| Rule | Does |
|---|---|
| `<slot>-review` | When `design_review.due` holds, asks for work of kind `design-review` on the draft. It waits while work or a worker of the design kind or its revise kind is active. It also waits while an interactive session is open. A failed run that ends with no review is tried again |
| `<slot>-review-fix` | When `design_review.fix_due` holds, asks for the revise kind with the reason `agent-review`. The limit is 6 rounds |
| `<slot>-review-publish` | When `design_review.finished` holds and no work or worker is active on the card, runs `publish-documents`. Product design publishes the documents with the tag `product-design`. Tech design publishes the tag `tech-design` and the tag `deploy-notes` |

The action `publish-documents` takes a `tags` list. It publishes each draft
document of the card that has one of the tags. A document that is not a draft
stays as it is. When the app refuses to publish a document, the action is
refused with `document-publish-refused`, and the pause rules apply.

The documents that get a design review are the documents whose tags the
template names. The app collects the `document` tag of each request rule that
has the role `design-review`. It reads the tags from the template copy of the
project, so a draft with a tag of your own gets its first review when you
change the tag in those rules. A template with no such rule asks for no design
review, and no draft gets one.

To turn the design review off, remove the six rules and the marker
`design-review-running` from the template. The writer then has to publish its
own draft.

### Automatic approval of a design

A design can approve itself in the pass that publishes it. The rule reads the entry check of the document.
The entry check records what stood open on the newest version when the document was published.

A publish moves a document out of Draft, and only a publish writes an entry check. The app counts the decisions with no
answer and the threads that are not resolved. A pending thread and an addressed thread both count. The app writes both counts and the
time on the newest version.

A document created in review writes no check. A revision writes none either, also when it moves the document out of
Approved or Changes requested, so the new version has no clean entry. An undo of a verdict is not a publish. A document that was in review before this feature has no check, so no rule approves it.

Five conditions read these facts. Each takes a `tag`:

| Condition | True when |
|---|---|
| `card.document.entry_decisions_open` | a document with the tag in review had a decision with no answer when it was published, or has no entry check |
| `card.document.entry_threads_open` | a document with the tag in review had a thread that was not resolved when it was published, or has no entry check |
| `card.document.rejected_before` | a document with the tag ever got a change request or a withdrawal, on any version |
| `card.document.published_in_column` | each document with the tag in review was published at or after the card arrived in its current column. The arrival is the time of the newest created or moved row of the card. A document with no entry check fails it, and so does a card with no such row |
| `design_review.passed` | the newest agent review of each document with the tag in review passed, and it read the newest version of the document. A document with no agent review fails it, and so does a review of an older version |

The last condition is in the **Agent review** group. The others are in the **Board** group.
An answer or a comment that comes after the publish changes none of these facts.

Lifecycle uses them in two rules. Each rule sits right after the `<slot>-review-publish` rule of its slot, so it fires in
the same pass as the publish. The `<slot>-approved` rule then moves the card. The marker pass runs last,
so no `document-in-review` item opens in your inbox.

| Rule | Does |
|---|---|
| `product-design-auto-approve` | Runs `approve-document` for the tag `product-design` when a document with the tag is in review, was published after the card arrived in its column, had no open decision and no open thread, never got a change request or a withdrawal, and has a passed agent review. It has no condition on the card type |
| `tech-design-auto-approve` | The same for the tag `tech-design`. It has no condition on the card type either |

The action `approve-document` takes a `tag`. It approves each document of the card that has the tag, is in review and has a
clean entry check on its newest version. The Workflow account writes the approval. The approval names the version of the check
and the newest review that the rule read. When a revision or a verdict landed after the rule read the facts, the app
refuses the approval. It also refuses when you cleared the answer of a decision after the document was published.
The action then writes a `verdict-failed` row in the history of the card and ends. It does not retry.
The document stays in review until you act on it.

The approval note on the document says that the document had no unanswered decision and no open thread, and that its agent
review had passed. This holds for the two rules above. The action `approve-document` itself checks only the clean entry
check. A rule that you add to the template copy can approve with other conditions, and the note then says more than the
rule checked.

You cannot edit the template of a running instance yet. The Workflow page is read-only, and the template file is part of the
release. A change to which designs approve themselves, such as a `card.type` condition on `tech-design-auto-approve`,
is a change to `config/workflows/lifecycle.yaml` with a migration that writes the file into each project. It reaches an
instance with the next release. A workflow editor will replace this path.
A template with no design review rule never approves a design this way, because `design_review.passed` stays false.
A rule with a `card.type` condition reads the card type live. When you change a card to an allowed type while a clean design is in review, the rule approves the design at the next pass.

### Undoing a design

When you withdraw the approval of a design, the card goes back to the stage that the design opened.
The undo rules read the withdrawal, and they run first in every pass, in every column.
A withdrawal by you counts the same as the withdrawal of an approval that a rule wrote.

| Condition | True when |
|---|---|
| `card.document.approval_withdrawn` | the newest review of the newest version of a document with the tag is a withdrawal of an approval. The withdrawal of a change request does not count. A new version of the document ends it. It is in the **Board** group |

Lifecycle has two rules. Neither rule has a slot. Each needs a card that is not in a terminal column
and not in the slot of the design.

| Rule | Does, in this order |
|---|---|
| `product-design-undo` | Runs `stop-work`. Asks for changes on the approved or in-review documents with the tag `tech-design`, with a note that says the approval of the product design was undone. Moves the card to Product design |
| `tech-design-undo` | Runs `stop-work`. Moves the card to Tech design |

The action `stop-work` cancels the open work requests of the card that a rule owns.
It also asks the bridge to stop each open background run of the card, and it writes one `stopped` row in the history of the card for each run.
The row reads "Loupe stopped the implement work." and names the rule.
It leaves an interactive session open, and the move closes the session as any move does. It leaves a request that you started from an inbox item.
A bridge that cancels a claimed request stops the worker and posts no result, so a stopped worker adds no refusal to the card.

An undone design waits for you. A design that was withdrawn counts as rejected, so the auto-approve rules do not approve it again.
When the tech design has changes requested and the card comes back to Tech design, the rule `tech-design-revise` asks for a revision.
A card that comes back to Implementation keeps its open pull request, so no new implementation request starts.

A card in a terminal column keeps its place. The withdrawal resets the document alone, and the other design keeps its approval.
The app moves the card as Loupe, so an epic with open children moves back like any other card.

A project that updates to this version gets the two rules in its template copy. Each past withdrawal counts as handled,
so no card moves when the update arrives. A withdrawal after the update moves the card.

### Human review for a new artifact

A card has a **Requires human review** switch (see [the card page](board.md)). The rule `human-review-on-artifact`
turns the switch on when an artifact that the card has not seen reaches it. It has no slot, and it skips a card in a terminal column.

An artifact reaches a card when it is linked to the card or to the parent of the card. A link inside a design document does not count.
A new artifact on an epic also reaches each child of the epic, so each child gets its own check.

| Condition or action | Does |
|---|---|
| `card.review.unseen_artifact` | True when an artifact that reaches the card is not in the seen list of the card. It is in the **Board** group |
| `require-human-review` | Turns the switch on, stores the artifacts that reach the card in its seen list, and writes a `human-review-changed` row in the history of the card with the cause `rule`. A card whose switch is on changes nothing |

You can turn the switch off while the artifact stays linked. The seen list then holds that artifact, so only a later new
artifact turns the switch on again.

A project that updates to this version gets the rule in its template copy. A migration also turns the switch on for each
card outside a terminal column that an artifact reaches today, and writes one history row with the cause `rollout`.
After the update, the rule fires only for an artifact that arrives later.

### Approval of a pull request

Loupe opens most pull requests as its GitHub App, and GitHub refuses a review from the author of a pull request.
So Loupe gates a pull request with a check run that it owns, `Loupe / Approval`. The `merge-ready` rule merges a
pull request into the default branch only when `pr.approval_check_passed` is true.

Two rules post the check. Both sit in the `in-review` slot, before `merge-ready`.

1. `approval-check-auto` fires when the pull request is open, it is not a draft, its base is the default branch, the
   checks passed on the head commit, the agent review passed, Loupe can post a check (`pr.can_post_check`), the card
   does not require a human review (`card.review.needs_person`), and the pull request has no conflict, no change
   request and no passed `Loupe / Approval` on the head commit.
2. `approval-check-person` fires when a person approved the head commit on GitHub and the checks passed. It posts the
   same success, so a person's approval opens the same gate.

A card with the **Requires human review** switch on never gets the automatic check. If a success already stands on the
head, the rule `approval-check-revoke` changes it to failure. It has no slot, so it also runs while the card sits in
another column. A person's approval on GitHub then posts the success again.

| Condition | True when |
|---|---|
| `card.review.needs_person` | The **Requires human review** switch of the card is on. For an epic it is also true when the switch of a child is on, or when a child pull request merged into the epic branch without a passed or overridden agent review. It is in the **Board** group |

An epic pull request into the default branch gets its own agent review, and then the same rules. The agent review of the
epic covers the whole change, also the merges of the default branch into the epic branch and their conflict fixes.

The wait item "Waits for approval" opens when a person must review the card, when Loupe cannot post a check on the
repository, or when the agent review failed and you can override it. A pull request that waits for the agent review
of a new commit opens no item. When `Loupe / Approval` passed and GitHub still blocks the merge, the marker
`merge-blocked` shows Needs you: "GitHub blocks the merge. Check the ruleset of the default branch."
The Repositories tab of the project settings lists the settings of each repository that do not fit.
Loupe reads rulesets only. It does not read classic branch protection. The steps below fix both problems.

#### Set up the ruleset

The check only gates a merge when the repository ruleset requires it. The Repositories tab of the project settings
writes these steps for each repository, with a `loupe-ruleset.json` file that you can import on GitHub. After the
import, open the ruleset and check that `Loupe / Approval` shows the Loupe App as its source. Select **Check again**
after a change. The same steps by hand:

1. Accept the new **Commit statuses: read and write** permission of the Loupe GitHub App on each installation.
   GitHub lists the App as a source only with that permission. See [forge webhooks](../extending/forge-webhooks.md).
2. Wait for the first `Loupe / Approval` run. GitHub offers the App as a source only after the App posted a check run
   recently.
3. In the ruleset, add the required status check `Loupe / Approval`, and set the **Loupe** GitHub App as its source.
   Without the source, any CI job or commit status can post the same name and forge the approval.
4. Remove the rule **Require approvals** from the ruleset. The App cannot give that approval on its own pull request.

A project that updates to this version gets the new rules in its template copy. A ruleset that still requires an
approving review blocks the merge of a pull request that the App opened, until the owner changes the ruleset.

### Asking about an unplanned child

A child that joins an epic after the epic design was approved may link no tech
design. The `unplanned-child` rule puts a question in the
[inbox](inbox.md#workflow-questions) of the project. The question names the
child and the epic, and it has three options:

1. **Link the tech design of the epic.** The child links the tech design of the
   epic, whatever its status. When that design is approved, the child starts
   at once if it has no open blocker. If the design is back in review, the
   child waits until the epic design is approved again. When the epic has no
   tech design, nothing is linked and the card history records the refusal.
   The implementation worker then builds the card body against the epic
   design. The build notes cover only the work that the card body describes.
2. **Move the card to Tech design.** The child gets a tech design of its own.
3. **Detach the card.** The card stops being a child of the epic.

An agent that files the child can answer the question before it opens. It
passes `childDesign` to `card_create` or `card_update`, with `inherit` for the
first option or `own` for the second. A child made that way gets no question. A
child that a person makes on the board, or that a caller makes with no choice,
still gets it. See [the MCP tools](mcp.md).

The answer is final. Loupe runs the option a short time after you answer,
because a queue carries the answer to the workflow. The question closes by
itself, as withdrawn, when the rule stops holding: for example when the child
links a design, when the epic starts new work, or when the card moves away from
Next. Loupe also withdraws it when the card is held or deleted. A
question that you answered stays answered.

When the inbox is off, the rule cannot ask. The child then pauses with the
reason `inbox-off`. Turn the inbox on, then release the pause, and the
question opens.

A workflow file declares the choice of an agent in a top-level `childChoices`
section. It maps `inherit` and `own` to a list of actions. Only `link-document`
and `move` are allowed there. Loupe runs the actions on the new card in the
same step that writes it, and a refused action undoes the write. A workflow with
no such section refuses `childDesign`.

In a workflow file, the action `ask` takes a `question` key and a list of
`options`. Each option has a `label` and a `then` list of actions. The action
`link-document` links a document of the parent card to the card. It takes
`from: parent` and a `tag`. When the parent has two documents with that tag, it
links the approved one. The action `detach` removes the parent of the
card. The `question` and each `label` are translation keys. They can use the
parameters `%child%` and `%epic%`, which hold the card numbers.

## The Simple template

Simple has no slots, and a person can make any move. It has two rules: a card
whose pull requests all finished with one merged moves to the terminal column,
and a card that reaches a terminal column asks for a teardown.

In both templates, a card whose pull requests all closed with none merged stays
in its column. A person or an agent moves it.

## Rules the app adds

Loupe adds its own rules to the rules of every template. The **Workflow** page
lists them in their own group, **Rules the app adds**, below the rules of the
template. The group shows only when the app adds at least one rule. An app rule
watches the Backlog, a terminal column, or every column, because each template
names its own slots. No template rule can take the id of an app rule.

A request of an app rule can carry a prompt that ships with Loupe. A bridge that
sets `appPrompts: true` runs it for a kind that its `work:` map does not hold.
See [Command-line bridge](../extending/cli-bridge.md#work-requests).

Every work request of the Lifecycle template also carries the prompt that Loupe
ships for its kind. This covers all 18 kinds, such as `tech-design`, `implement`,
`fix`, `merge` and `repair`. A `work:` entry of the bridge rule file still wins
over the shipped prompt.

The app adds seven rules.

The rule `discovery` watches the Backlog. It asks for work of
kind `discovery` when the card carries a requested
[discovery run](workshop.md#run-discovery), and it sends the discovery prompt
with the request. The rule sets `onNoTaker: fail`, so the request fails at once
when no online bridge could take it, and the run fails with the cause: no bridge
serves the project, or a bridge is online and cannot run discovery. A request
that a capable bridge does not claim waits until a bridge claims it.

The rule `discovery-revise` also watches the Backlog. It asks for work of kind
`discovery-revise` when you asked for changes to the report of a
[discovery run](workshop.md#review-the-report), and it sends the revision prompt
with the request. The worker submits a new version of the same report. An
approval of the report while the worker revises it cancels the request. The rule
also sets `onNoTaker: fail`, so the run fails at once with the cause when no
online bridge can take the revision.

The rule `discovery-approved` also watches the Backlog. It moves the card to the
first terminal column when the owner approved the report of the discovery run.
The file of the app rules also names the slot where the cards of the ticked
proposals land, with the key `proposalSlot`. Loupe ships it as `next`.

The rules `post-widget-review` and `sync-site-review-check` watch every column.
They act on the verdict that a reviewer sends from the
[live-review widget](live-review.md). Each reads one condition of the Board
group, and each fires one write. The rules `sync-agent-review-check` and `comment-agent-review` also watch
every column. They read conditions of the Agent review group:

| Rule | Condition | Write |
|---|---|---|
| `post-widget-review` | `card.site_review.verdict_unsent`: a verdict of the card has a pull request that no review settled yet | `post-review` |
| `sync-site-review-check` | `card.site_review.check_stale`: an open GitHub pull request of the card has no check for its head commit, a check with another result than the one the card now wants, or a check that lists other notes than the card now carries | `site-review-check` |
| `sync-agent-review-check` | `agent_review.unposted`: a stored agent review of the card has no check on the forge yet, on its own head or on a head it was carried to | `agent-review-check` |
| `comment-agent-review` | `agent_review.uncommented`: the newest review of an open pull request has findings, its first check on its own head landed in the last hour, and it has no summary comment | `comment`, with `comment: agent-review` |

The review is posted under the reviewer's own account. The reviewer connects
that account from the widget. A review that the connection cannot send, because
it is missing or expired, stays refused with the reason `connection-expired`.
The next connection of the same reviewer sends it again.

The check counts the pending notes that a verdict carried. A note that no
verdict carries yet does not count. The check is green when no such note is
pending. It fails when one or more are pending, and its summary lists them. A
push to the pull request, and a note that turns addressed or resolved, post the
check again.

Both rules need the engine to read the facts of the Board module. When it
cannot read them, only these two rules wait. Every other rule of the card runs.
Neither rule can be removed from a project, but each write stays off until its
opt-in is on.

## Kinds of work

A rule that asks for work names its kind. A bridge runs a kind only when its
`work:` map has an entry for it. Lifecycle asks for these kinds:

| Kind | Asked for |
|---|---|
| `product-design` | A product design session. It needs a bridge that can open an interactive session |
| `product-design-revise` | A revision of the product document |
| `tech-design` | The tech design |
| `tech-design-session` | An interactive tech design session. It needs a bridge that can open an interactive session |
| `tech-design-revise` | A revision of the tech design |
| `design-review` | An agent review of a draft product document or tech design |
| `tech-design-recheck` | A check of an approved tech design against the code, after a card that blocked it is done |
| `implement` | The implementation and its pull request |
| `breakdown` | The child cards of an epic |
| `fix` | A fix of a failed check, a conflict or a request for changes |
| `rebase-stacked` | A new base for a stacked pull request, when the project has no GitHub App installation |
| `sync` | An update of a branch that is behind, when the project has no GitHub App installation |
| `sync-epic` | A merge of the default branch into the epic branch, for a child whose blocker outside the epic is done, when the project has no GitHub App installation |
| `merge` | A merge, when the project has no GitHub App installation |
| `teardown` | The removal of the card's worktree |
| `epic-preview` | A refresh of the epic preview, when the head of the epic pull request has no refresh that ended well |
| `repair` | A repair of the cause after the work of a rule failed and its retries ran out |

Simple asks for `teardown` alone.

Each request of a shipped template carries a `checks` list. The list names what
that work needs from the project, such as a worktree per card or a test command.
A [discovery run](workshop.md#run-discovery) reads the lists through the
`workflow_get` MCP tool, and checks the project against them. A project bound
before this release gets the lists through a migration.

## What a request carries

A request for work carries the context of its card, as the card was when the
request opened. The bridge can fill a prompt or a command with each value.

A rule that reads a pull request tries the open pull requests of the card from
the bottom of a stack first, then the oldest opened. It acts on the first one
that makes its condition true. The fix limit counts per pull request: when the
rule moves to another pull request, its count starts again. The count also
starts again while that pull request is healthy: its checks passed, it has no
conflict, and no review asks for changes. A fix that works therefore does not
use up the limit. A card that paused at the fix limit continues on its own when
its pull request turns healthy. The `refill` parameter of the fix rules in the
Lifecycle template sets this. It reads the pull request that the rule acts on,
so a rule whose condition reads no pull request never refills.

| Value | What it holds |
|---|---|
| Pull request number | the number of the pull request that the rule acts on |
| Pull request link | the link to that pull request, as the card holds it |
| Head commit | the head commit of that pull request. A later push leaves it behind |
| Reason | `conflict`, `checks-failed` or `changes-requested`, from the state of that pull request, or the code that the optional `reason` parameter of the request rule sets, such as `agent-review`. A `repair` request holds the refusal code of the failed work |
| Document | the id of the document that a revision works on |

A request rule can set the optional `role` parameter: `code-review`,
`design-review`, `design-revise` or `fix`. The role says what the work does,
whatever its kind. The request and its worker runs keep it. The review and fix
MCP tools find the running worker by its role, so a project can give the work
another kind and keep the role. A fix request, one with the role `fix`, also
writes a `fix-requested` event to the card history. The event
holds the same reason. A request rule can set the reason with the optional
`reason` parameter. The parameter replaces the reason that the state of the
pull request gives, in the context and in the event. The fix rules for the
[agent review](#agent-review) use `reason: agent-review`.

A request rule can set the optional `onNoTaker` parameter to `fail`. The engine
then expires an open request of the rule as soon as no online bridge that
follows the project could take it. The card does not pause, and the module that
owns the work reads the cause from the reason of the request. A request without
the parameter waits until a bridge takes it.

The `move` action takes the optional parameter `outcome`. Its value must be a
key from the `outcomes:` list of the template, as [Outcomes and metrics](#outcomes-and-metrics)
says. A move that sets it records the outcome in the card history. The `merged`
rule of the Lifecycle and Simple templates sets `merged`. A template that
sets an outcome which its list does not declare fails to load, and the error
lists the outcomes it declares.

A request rule can set the optional `cancelWhen` parameter. It holds a
condition. While the condition is true, the engine withdraws the live request
of the rule. The design review
rules use it, so that a person who publishes or reviews the draft cancels the
agent work on it. With `cancelOnly: open` the engine withdraws only a request
that no bridge has taken. A request that a bridge claimed stays. The Product
design session rule uses it.

A request rule can name a prompt with the optional `prompt` parameter. The value
is a name of lowercase letters, digits and hyphens, such as `implement`. The
engine reads the text from `config/workflows/<template key>/prompts/<name>.md`
and sends it with the request. A bridge runs it for a kind that its `work:` map
does not hold, when it sets `appPrompts: true`. A template that names a prompt
with no file is refused when it is bound. A project bound earlier keeps its copy.
The request then carries no prompt when the file is gone.

A request rule names its document with the optional `document` parameter. It is
a map with the key `tag`, and an optional `status`: `in-review`, `approved`,
`changes-requested` or `draft`.

```yaml
- id: tech-design-revise
  slot: tech-design
  when: { card.document.changes_requested: { tag: tech-design } }
  then:
      request:
          kind: tech-design-revise
          document: { tag: tech-design, status: changes-requested }
```

The request then names the one unarchived document of the card that carries the
tag, and that has the status when the map names one. No such document refuses
the action with `document-not-found`. Two or more refuse it with
`document-ambiguous`. A refused action retries and pauses as
[Pauses and retries](#pauses-and-retries) says. In Lifecycle, the product design
revision names the document with the tag `product-design` and changes requested.
The tech design revision names the one with the tag `tech-design` and changes
requested.
An approved document with the same tag does not count. A request rule with no `document`
parameter names no document.
