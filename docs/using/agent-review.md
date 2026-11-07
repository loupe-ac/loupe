---
title: "Agent review"
description: "How a separate agent reviews the pull request of a card against its designs, what the review posts on the pull request, how a failed review starts a fix round, and when a person must approve."
---

A separate agent reviews the pull request of each card. The agent that wrote
the code never judges it. The review runs on the Lifecycle
[workflow](workflows.md) template, while the pull request is still a draft.

This page describes what you see and what you can do. The rules and the
conditions behind it are in [Agent review](workflows.md#agent-review) on the
workflows page.

## What the reviewer reads

The reviewer reads one head commit of the pull request. It reads the whole
change each time, and it keeps no memory of earlier rounds. It does not edit
the code, it does not approve the pull request, and it does not move the card.

The review checks two things.

- The code. The reviewer looks for bugs, breaks of the conventions of the
  repository, missing tests, and dead code.
- The pull request against its designs. The reviewer reads the card, the product
  document, the tech design, the answers to their decisions, the prototype, and
  the build notes. It reports a requirement that nothing builds, a decision that
  the change makes differently, a behaviour that no requirement asks for, and a
  deliverable that a design names and the diff lacks, such as a documentation
  page. These findings have the category `spec`.

A choice that the implementer made on purpose, with a reason in the "Departures"
section of the build notes, gets no finding. A departure with no record does.

An epic gets its review only when all its children are finished, because the
review compares the pull request with the whole tech design.

## What the review posts

Each review has findings. A finding has a severity, a title, a body and, for
most findings, a file and lines.

| Severity | Meaning |
|---|---|
| `important` | The change is wrong, or it differs from the design with no recorded reason |
| `nit` | A small improvement |
| `pre-existing` | A problem in code that the change does not touch |

On the Lifecycle template, only an `important` finding fails the review.

The result goes to the pull request in two places:

- A check named `Loupe / Agent review` on the reviewed commit. It fails when the
  review fails. Each finding shows as a note on its lines, and the summary lists
  the `spec` findings first.
- One comment on the conversation tab, when the review has findings. It holds
  the verdict, the summary of the agent, up to 20 finding titles, and links to
  the check and the card.

Both go out as the Loupe GitHub App. A project with no GitHub App installation
gets no check, and its reviews stay stored.

A new head whose change is identical to a reviewed head carries the review, and
the check says so. A different change gets a full review.

## A failed review

A failed review starts a fix round. A worker fixes the problems that the
findings name. The worker fixes the rule behind a finding at every place in the
pull request, and it adds a test for the rule. The worker pushes, and the new
head gets a new review.

A finding that needs evidence instead of code gets an answer in the build notes.
The reviewer reads that answer as a claim and checks it.

Some `spec` findings need you. The design may be unclear, or the change may
depart from it with no record. The reviewer marks such a finding, and the check
labels it "waits for the owner". The fix worker fixes the other findings first.
It then asks you one question in your [inbox](inbox.md). The worker pushes only
after you answer.

## Override a failed review

An approval of the pull request on GitHub can override a failed review. It must
cover the current head, and it must come after Loupe stored the review. The
workflow then treats the review as accepted, and no fix round starts for the
findings. When the approval is dismissed, or someone requests changes, the
override ends. An approval in the Loupe inbox does not override, because the
inbox does not post it to GitHub.

## Who approves the merge

The **Requires human review** switch of the card decides who approves the pull
request. See [the board](board.md). The switch starts off. It turns on by itself
when a new artifact reaches the card, and you can turn it on or off. A
[product design session](interactive-sessions.md#product-design) asks you about
it.

- With the switch on, a person approves the pull request on GitHub. Loupe then
  posts a passing `Loupe / Approval` check.
- With the switch off, a passing agent review and green checks are enough. Loupe
  posts a passing `Loupe / Approval` check. It posts a check and not a GitHub
  approval, because GitHub refuses a review from the author of a pull request.

The setting `merge-approval` of the repository can still hold the merge for a
person, and so can the rules of the base branch on GitHub. See
[The merge question and the held merge](workflows.md#the-merge-question-and-the-held-merge)
and [Approval of a pull request](workflows.md#approval-of-a-pull-request).

## Turn the review off

The review is a set of rules in the workflow template. To turn it off, remove
those rules. See the end of
[Agent review](workflows.md#agent-review) for the list.

## Related pages

- [Workflows](workflows.md) holds the rules and conditions.
- [Agent review of a design](workflows.md#agent-review-of-a-design) covers the
  review of a product document or a tech design, which is a different review.
- [Interactive sessions](interactive-sessions.md) explains how a card gets its
  pull request when you work with an agent yourself.
