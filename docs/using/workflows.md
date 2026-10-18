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

A new project picks its template during setup. The template creates its columns
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

The page lists the conditions of each rule. It groups them by the module whose
data they read: Board, Forge, Bridge or Inbox. A rule whose condition no longer exists
on this instance gets an amber tag that names the condition.

## Markers

A marker tells a reader what a card needs now. The template defines every marker.
The board, the list, the card page and the MCP tools show the markers that the
engine stores on the card.

A marker has these parts:

- A key, such as `checks-failed`. The MCP tools call it the `code`.
- A kind. See below.
- A label. This is the reason that a reader sees.
- A remedy. This is one line that says what clears the marker.
- A link, optional. The only value is `agents`. The card page then shows a link to the Agents page under the remedy.
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
lists every other marker, chips included. A card with only a chip shows a neutral
mark.

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
| `run-stopped` | Stuck | The last worker run gave up, was blocked, failed or had no result, and no work is under way |
| `checks-failed` | Stuck | A pull request has failed checks and no fix is under way |
| `conflicting` | Stuck | A pull request has a conflict and no fix is under way |
| `ready-not-merged` | Stuck | A pull request stayed ready to merge for the stuck delay and no merge is under way |
| `pull-request-idle` | Stuck, fallback | An open pull request had no card history for the stuck delay |
| `nothing-happened` | Stuck, fallback | A card in Product design, Tech design, Implementation or In review had no card history for the stuck delay |
| `document-in-review` | Needs you | A document of the card waits for your review |
| `waits-for-approval` | Needs you | A pull request waits for your approval |
| `open-question` | Needs you | An inbox question names the card |
| `session-open` | Working | A person has an interactive session open on the card |
| `design-review-running` | Working | A draft design document of the card is in its agent review. The label shows the round, such as round 2 of 3 |
| `run-open` | Working | A worker runs on the card |
| `no-bridge-online` | Stuck | Work waits, and no online bridge could take it. The card page links to the Agents page |
| `bridge-paused` | Waiting | Work waits, and the only online bridges that could take it are paused. The card page links to the Agents page |
| `work-requested` | Working | Work waits for a bridge to take it, and a bridge could take it |
| `forge-request-pending` | Working | Loupe asked the forge to change a pull request and the forge did not answer |
| `held-by-blocker` | Waiting | A card that blocks this card is still open |
| `waits-for-parent-card` | Waiting | A pull request waits for the pull request of its parent card |
| `waits-for-parent-branch` | Waiting | A pull request waits for a branch that no card owns |
| `waiting-on-checks` | Waiting | The checks of a pull request run |
| `unmanaged` | Chip | A person or an agent made the card unmanaged |

The Simple template declares all of these markers except `pull-request-idle`,
`nothing-happened`, `document-in-review` and `design-review-running`, because Simple has no slots.

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


A marker reads the same conditions as a rule.
markers:

- `card.paused`: the card has an active pause.
- `card.document.in_review` with a `tag`: a linked document with that tag is in
  review.
- `card.quiet_for`: nothing happened on the card for the `delay`, in minutes.
- `card.rule.held_by_blocker`: an open blocker alone holds a move rule of the
  card.
- `card.run.stopped`: the latest run of the card ended with a warning, and the
  card did not move after it ended.
- `card.run.session_open`: a person has an interactive session open on the card.
- `design_review.running` with a `tag` and `rounds`: a draft document with that
  tag has an agent review that is not finished. The label of the marker shows
  the round and the `rounds` limit.
- `card.held`: a person held the agents of the card.
- `card.run.work_no_taker`: a work request of the card waits, and no bridge that
  follows the project, reports the kind of work and was seen in the last three
  heartbeat intervals could take it. The marker counts from the later of the
  start of the wait and the moment the last such bridge went quiet.
- `card.run.work_taker_paused`: a work request of the card waits, and every
  online bridge that could take it is paused. A bridge counts as paused while
  you paused it, or while its last heartbeat said so.
- `card.inbox.open_question`: an open inbox question names the card. The
  optional `blocking` is `true` to match only a blocking ask, or `false` to
  match only a non-blocking one, such as a to-do. With no `blocking`, any open
  ask matches. Lifecycle and Simple set `blocking: true`, so a non-blocking
  to-do does not show "Needs you".
- `pr.checks_pending`: the pull request is open, and its checks run.
- `pr.waits_for_approval`: the pull request is open, not a draft, green and
  into the default branch, and no current approval covers its head.
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
rule reads changes after the refusal, such as a new head commit. The work then
starts again. The end of the card's own request does not count as a change, so
a worker that blocks does not start a new run on the same card. A retry after a
refusal also waits for its delay.

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
next failure can start one more repair. A bridge with no `repair` entry lets
the request expire after the work timeout, and the card then pauses with "no
bridge took the work". [The development
lifecycle](../contributing/lifecycle.md) shows a `repair` entry.

A resumed run ends the pause of its card. When a person resumes a run, or a
closed [inbox](inbox.md) ask resumes it, the workflow releases the pause as soon
as the resumed run reports to Loupe. This holds for every pause a person can
release, and not for a pause that a rule sets with its own release condition.
The card then asks for no second worker. A resumed run that fails earns the
retries of a fresh budget, and a resumed run that ends as blocked pauses the
card again at once.

The template sets this behaviour in its `onWorkFailed` block:

```yaml
onWorkFailed:
    retryOn: [failed, timeout, still-true]
    retries: 3
    backoffMinutes: [2, 3, 5]
    repair: { kind: repair }
```

`retryOn` lists the refusal codes that retry, and `retries` counts the retries
before the repair. `backoffMinutes` gives the wait before each retry. `repair`
names the kind of the repair request. A template with no `repair` key pauses
the card when the retries run out. A template with no `onWorkFailed` block
neither retries nor pauses after a refused request.

A request can also end with no result while its rule still holds. A `request`
action that sets `stillTrue: retry` counts such an end as a failed try with the
code `still-true`. The rule then asks again after the next wait, and `retryOn`
must list `still-true`, or the first such end pauses the card. The Lifecycle
review rules set it, so a review run that ends with no review, such as one that
saw a new head, asks for a review of the current head again.

A work request that no bridge takes within 2 hours pauses the card with "no
bridge took the work". A teardown request is the exception: it expires with no
pause. The inbox then shows the item **Card paused: no bridge took its work in
time**. It tells you to check that a bridge is online, then to open the card and
resume it. Before that, work that waits 10 minutes with no online bridge opens
the notice **No bridge can take work**, as [the inbox guide](inbox.md#notices)
says.

A person ends a pause with **Retry now** in the Workflow panel of the card. The
button shows for a pause after too many retries, after the work limit, after
no bridge took the work, or after the worker stopped. It needs the permission to manage the project. A
release is refused on an unmanaged card. It is also refused on a pause that a
rule made, because that pause ends only on its own release condition. After a
release the paused rule runs again with a fresh budget of retries and work. The
card history records the pause and the release, with the person who released
it. A pause that the workflow ends by itself shows in the history too, with
Loupe as the actor and the reason. An agent ends a pause with the `card_pause_release` MCP tool.

## Writes to GitHub

A rule can write to a pull request: merge it, update its branch, change its
base, open an epic pull request, switch it between draft and ready, close it,
or post a comment on it. The rule
alone decides each write, and the write happens when the rule fires. When the
project has no GitHub App installation, the rule asks a bridge for the same work
instead. See [Automation](board.md#automation).

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

Two more writes come from the [rules the app adds](#rules-the-app-adds). They
act on a site-review verdict, and they never ask a bridge for work:

| Write | Does |
|---|---|
| `post-review` | Posts each unsent verdict as a review on the open GitHub pull requests that the reviewer picked. The review goes out under the reviewer's own GitHub account |
| `site-review-check` | Keeps a check named "Loupe site review" on each open GitHub pull request of the card. The check counts the notes of every card that links the pull request. The check goes out as the GitHub App |

A review that the reviewer sends on their own pull request becomes a comment,
because GitHub refuses a review from the author of a pull request.

The `review-ready` write marks the open pull request that the rule acts on as
ready for review. It skips a closed or merged pull request. It has no fallback.

The `agent-review-check` write posts the reviews that a `review` worker sent
with the `agent_review_submit` MCP tool. A review of an open GitHub pull request
of the card gets a new check named `loupe/agent-review` on the commit it
reviewed. The check fails when a finding has a severity that the template
counts as failing. Each finding shows as a note on its lines. The write has no
fallback. A project with no GitHub App installation gets no check, and its
reviews stay stored.

The `comment` write with `comment: agent-review` posts one comment on the pull
request for a review that has findings. The comment holds the verdict, the
summary of the agent, up to 20 finding titles, and links to the check and to the
card. It leaves out the text of each finding. The comment goes out as the GitHub
App, and it has no fallback. See [Agent review](#agent-review).

## The Lifecycle template

Lifecycle has the slots Next, Product design, Tech design, Implementation and In
review. A person can move a card from the Backlog to Next, Product design or
Tech design, and from Next back to the Backlog or on to a design slot. A person
can also move a card from the Backlog or from Next straight to the terminal
column, for example when the work is no longer needed. A person can move a card
from Implementation back to Tech design, for example when the card has no tech
design yet. A card whose tech design is approved goes
back to Implementation at once when it has no open blocker.

| Slot | The workflow |
|---|---|
| Product design | Asks for an interactive product design session when the card has no product document, and asks for a revision when the product document gets changes requested. When every document with the tag `product-design` is approved, the card moves to Tech design. The session request also carries three [discovery](workshop.md#run-discovery) checks, for a design system, for a Claude Design project that mirrors it, and for a starter Claude Design project. When the project settings file names a design system, the product document lists the components that the change reuses or changes. A draft product document gets an [agent review](#agent-review-of-a-design) before the app publishes it to you |
| Tech design | Asks for the tech design when the card has no design document, and for a revision when it gets changes requested. When every document with the tag `tech-design` is approved, the card moves to Implementation once it has no open blocker. A card whose blocker is done gets one recheck first: an agent reads the approved design against the code on `main`, and the card moves on when the design still holds. See [Recheck of a design](#recheck-of-a-design). A draft tech design, with a draft deploy notes document, gets an [agent review](#agent-review-of-a-design) before the app publishes it to you |
| Implementation | Asks for the implementation, or for a breakdown of an epic into children. An epic gets its breakdown when it enters, with or without children. A pull request that is open, not a draft, whose required checks passed and whose [agent review](#agent-review) passed moves the card to In review. Failed checks, a conflict or a request for changes ask for a fix, 3 rounds at most. A card with no pull request whose implementation run finished with the reason `nothing-to-build` or `delivered-without-code` moves to the terminal column. See [Finishing without code](#finishing-without-code) |
| In review | Asks for a fix as in Implementation. A pull request that turns back into a draft moves the card to Implementation. A stacked pull request whose parent merged gets a new base. An approved pull request that is behind gets its branch updated. A ready pull request merges once its agent review passed |

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
while the card links a pull request, while it is an epic, and while work runs on
it. The card history says "Via the nothing-to-build workflow rule" or "Via the
delivered-without-code workflow rule". The worker also adds one line to the card
body that names the decision or the document. A parent epic counts the card as
finished, as it counts any card in a terminal column.

In a workflow file, the condition `card.run.finished_with` with a `kind` and a
`reason` is true when the latest work request of the card is done, of
that kind, with that reason. A bridge older than this release sends no reason
with a done request, so the rules never fire for its runs.

### Agent review

A separate agent reviews each open pull request of a card while it is still a
draft. The review belongs to one head commit. A card of a type that may have
children, such as an epic, needs no review. The rules of the template turn the
review on. A template with none of the rules below asks for no review, and its
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
tech design, the answers to their decisions, and the plan. A finding of the
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
implementer chose on purpose to depart from a design, the plan document records
the departure under a "Departures" section, with the reason. The review then
shows it as a nit, which never fails the review, and the fix worker leaves it
alone. A departure with no record fails the review.

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
| `agent_review.due` | the card is not an epic, and the head commit of the pull request that the rule acts on has no review, own or carried. It is also true when the newest review failed and a fix round for the agent review ended done after that review began, so the same commit gets a new review. It is false while an approval overrides the review |
| `agent_review.failed` | the newest review of the head commit of the pull request that the rule acts on failed, own or carried. It is false once a fix round for the agent review has ended done after that review began, until the next review lands. It is also false while an approval overrides the review |
| `agent_review.passed` | the card is an epic, or every open pull request has a head commit and a review of it that passed. It is false when no open pull request has a head commit |
| `agent_review.overridden` | the card is not an epic, every open pull request has a head commit and a review of it that passed or that an approval overrides, and an approval overrides at least one |
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

A pull request is ready to merge when it is reviewable, an approval covers its
head, its base is the default branch or the card's epic branch, and it has no
conflict. A child pull request into its epic branch also waits while any
worker run of the epic is open. A merge from the base after the approval keeps
the approval. New commits need a new approval.

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
   moves no follow-up.
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
3. The loop ends when a review passes, or after 6 failed reviews, so a draft gets up to 5 revisions. The app then
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
`tag`. Each takes the `tag` and `rounds`, the number of failed reviews that
ends the loop:

| Condition | True when |
|---|---|
| `design_review.due` | a draft with the tag has a version that no review read, or a review that a revision answered, and its loop is not finished |
| `design_review.fix_due` | the review of the current version of a draft with the tag failed, has no answers, and asks for a revision |
| `design_review.finished` | each draft with the tag has a finished loop, and at least one such draft exists. A loop is finished when the review of the current version passed, or a fix round answered a passed review with a question for the owner and revised the draft when an answer is fixed or for the owner, or the draft used all its failed reviews |
| `design_review.running` | a draft with the tag has a loop that is not finished. The marker `design-review-running` reads it |

Lifecycle uses them in these rules, for the slots Product design and Tech
design. Each name starts with the slot key, `product-design` or `tech-design`:

| Rule | Does |
|---|---|
| `<slot>-review` | When `design_review.due` holds, asks for work of kind `design-review` on the draft. It waits while work or a worker of the design kind or its revise kind is active. In Product design it also waits while an interactive session is open. A failed run that ends with no review is tried again |
| `<slot>-review-fix` | When `design_review.fix_due` holds, asks for the revise kind with the reason `agent-review`. The limit is 5 rounds |
| `<slot>-review-publish` | When `design_review.finished` holds and no work or worker is active on the card, runs `publish-documents`. Product design publishes the documents with the tag `product-design`. Tech design publishes the tag `tech-design` and the tag `deploy-notes` |

The action `publish-documents` takes a `tags` list. It publishes each draft
document of the card that has one of the tags. A document that is not a draft
stays as it is. When the app refuses to publish a document, the action is
refused with `document-publish-refused`, and the pause rules apply.

To turn the design review off, remove the six rules and the marker
`design-review-running` from the template. The writer then has to publish its
own draft.

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
   design. The plan covers only the work that the card body describes.
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

The app adds five rules.

The rule `discovery` watches the Backlog. It asks for work of
kind `discovery` when the card carries a requested
[discovery run](workshop.md#run-discovery), and it sends the discovery prompt
with the request. A request that no bridge takes expires, and the run fails with
the reason that no bridge took the work.

The rules `post-widget-review` and `sync-site-review-check` watch every column.
They act on the verdict that a reviewer sends from the
[site-review widget](site-review.md). Each reads one condition of the Board
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

A fix request also writes a `fix-requested` event to the card history. The event
holds the same reason. A request rule can set the reason with the optional
`reason` parameter. The parameter replaces the reason that the state of the
pull request gives, in the context and in the event. The fix rules for the
[agent review](#agent-review) use `reason: agent-review`.

A request rule can set the optional `cancelWhen` parameter. It holds a
condition. While the condition is true, the engine withdraws the live request
of the rule. The design review
rules use it, so that a person who publishes or reviews the draft cancels the
agent work on it.

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
