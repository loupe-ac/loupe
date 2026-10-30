---
title: "The admin area"
description: "Instance status, feature flags, the waitlist, beta invites, the audit log and the live-review outbox. Requires ROLE_ADMIN."
---

`/admin` is the dashboard. Everything below it needs `ROLE_ADMIN`, which
`app:user:promote` grants and `app:admin:create` grants while also verifying the
account.

## Instance status

**`/admin/status`** reports, for this instance: whether the mail transport
accepts a connection, whether the sender address is still the undeliverable
default, whether the message queue is being drained, how many messages have
failed, whether the Mercure hub answers, and — when billing is on — whether the
Stripe keys are set.

The install wizard shows the same page before it creates your administrator, so
a broken mailer is visible *before* it can lock you out.

The worker check is deliberately honest about its limits. A running worker
leaves no lasting trace, so an empty queue is reported as **unknown**, not as
healthy. What it can prove is the failure: messages sitting available and
unclaimed for over a minute mean nothing is consuming them.

## Feature flags

The flags page lists every flag the database defines, and its scan view lists
every flag the code references but the database does not — creating those rows
on request.

A missing row is not an error. Every install flag falls back to exactly the
value the wizard would have written (registration on, no cap, billing and social
login off, a 14-day trial), so an instance recovered entirely from the shell
behaves like a wizard-installed one with the defaults accepted.

Some flags gate integrations rather than behaviour: `billing.enabled` decides
whether the Stripe client is ever instantiated, and `auth.google.enabled` /
`auth.github.enabled` decide whether a social provider is reachable at all — its
credentials alone are not enough.

`review.mermaid.enabled` is seeded off. When it is on, the review page draws
Mermaid diagrams, and each reader's browser loads Mermaid from jsDelivr. See
[Diagrams](documents.md#diagrams).

The **Open Graph image** flag, `open_graph.image`, picks the preview image of a
shared link to a public page. It is a list flag with two values, `hero` and
`board`, and `hero` is the default. The flags page lists the flag by its key. A
change applies to every public page on the next page load. Sites such as X and
Reddit can keep the old image for a link that they already fetched.

The **site link** flags, `site.link.discord`, `site.link.x` and `site.link.source`,
hold the outside links of the site footer and the app. Each is a text flag that
takes a full `http` or `https` address. `site.link.discord` and `site.link.x`
seed empty, so an admin sets them on the flags page. An empty flag hides the
Discord and X links. An empty `site.link.source` makes the app use `APP_SOURCE_URL`. Any other
value that is not a web address counts as empty. A change applies on the next
page load. The docs pages take it at the next start of the web container.

The **merge queued evaluations** flag, `workflow.merge_queued_evaluations`, makes
the worker evaluate a card once for all the triggers that wait together in the
queue. A trigger that arrives while an evaluation runs still queues one more
evaluation. A fresh install seeds the flag on. An instance that upgrades gets it
off, because a worker of the old build never clears the queue marker that the
new code writes. Switch the flag on at the flags page after the web container
and the worker container both run the new build. See
[Post-deploy checks](../operating/post-deploy-checks.md) for how to read the
worker build. To roll back, switch the flag off first, then deploy the old build.

## Audit log

**`/admin/audit-log`** lists every recorded audit event on the instance, newest
first. Each row carries the operation, the outcome, the actor, the channel the
actor used, the subject and a context object. Filter by actor name, by operation
prefix, by channel, and by a date range.

### Retention

The trail keeps **180 days** by default. An hourly scheduled task deletes every
record older than that window, and `audit:purge` is the manual backstop. Both
read the same window. Like everything else on the schedule, the task runs only
if a worker is consuming. See [Commands](../reference/commands.md).

The `audit.retention_days` feature flag sets the window, and you change it in
the admin area with no restart. An instance installed with the audit log already
has the flag row. Edit it at **`/admin/feature-flags`**.

An instance installed before the flag existed has no row for it. That instance
keeps 180 days, which is the value of `retention_days` in
`config/packages/ubermuda_audit.yaml`. To change the window there, create the row at
**`/admin/feature-flags/new`**. Set **Name** to `audit.retention_days`, set
**Type** to `Int`, then put the number of days in **Value**.

A window below one day is read as one day.

Back up the audit table if you must keep records for longer than the window. The
purge is a hard delete and it is not reversible.

### What account deletion removes

Deleting an account removes what that account did, and keeps what was done to
it.

- Records the account is the actor of are deleted.
- Records written by an app the account connected are deleted.
- Records that only name the account as a subject are kept whole. Such a record
  holds a subject type and an id, never a name, and it carries the acting
  party's name instead. Deleting it would erase an admin's own record of what
  they did.
- The records the deletion itself writes carry no name for the account they
  erase.
- The waitlist entry that has the email address of the account is deleted.

## Waitlist

When a registration cap is closed, `/waitlist` collects addresses and
`/admin/waitlist` works through them: invite a single entry, invite a selection,
or invite the oldest. Redeeming an invite converts the entry into an account.

A person who joins the waitlist can also pick what they want Loupe to support,
under the question "Which forges and agents do you use?". The forges are
GitLab, Bitbucket, Gitea, Forgejo and GitHub Enterprise. The agents are Cursor,
Gemini CLI, OpenCode, Aider and Pi. The person can also write a short text under
"Something else?". The landing page of the hosted instance shows the form behind
its **Notify me** button.

The confirmation that a person sees after joining shows a card with a link to the
Discord server, while the `site.link.discord` flag holds a link.

Above the table, the page shows how many entries picked each choice. The
counts run from the most picked choice to the least. They cover the ten named
choices. The "Something else" text is not counted.

### Waitlist emails

The **Waitlist notification emails** flag, `waitlist.notification_emails`, holds the
addresses that get an email about the waitlist. Separate the addresses with
commas. White space and new lines also work. An empty flag turns the emails off.

The app sends one email to each address in these cases:

- A person joins the waitlist with the form.
- A person joins the waitlist through a social login while sign-up is full.
- A person whose account is disabled joins the waitlist again.
- A person registers with a waitlist invite link, by the form or by a social login.

Each address gets its own email, so no recipient sees the other addresses. The
email names the person and says how they joined. The join email also lists their
picks and their "Something else?" text. Both emails give the number of people
who still wait, and a button to the waitlist or to the new account.

A picks update, a repeated join and a registration with no invite send nothing.
An email that fails to send never changes what the person sees. The app logs
`account.waitlist_notification_failed` and goes on.

A fresh install starts with the address of the first administrator in the flag.
An upgraded instance starts with the addresses of every administrator who can sign
in. The agent account and a suspended administrator are left out. Empty the flag
to stop these emails.

The flags page saves any text. **Instance status** shows a warning when an entry is
not an email address, and the warning names every such entry. The app skips these
entries and sends to the others.

A person's waitlist entry is deleted with their account. It appears in their
[data export](data-exports.md) as `waitlist.json`.

## Beta invites

`/admin/beta-invites` makes single-use links for beta testers. Type an optional
note that says who the link is for, then select **Create link**. The page shows
the link. Copy it and send it to the tester. Each unused link in the list has a
**Copy** button, so you can copy it again later.

The link opens `/beta/<token>`. It lets one person sign up when the
registration cap is full, with the form, GitHub or Google. A signed-out visitor
selects **Continue to sign up** to reach the sign-up page with the link applied.
The new account gets a comp that never expires. A person who already has an
account opens the link and selects **Claim free beta access**, and their account
gets the comp. If they are signed out, they select **Continue to sign up** and
sign in from the sign-up page. The link then opens again for them to claim.
When registration is switched off, the link creates no account, and it answers
404 to a signed-out visitor. A signed-in account can still claim it.

After a tester redeems a link, the page that opens shows the welcome page in the
sign-in frame. It has two cards: **Start using Loupe**, and **Join the beta
testers** with a Discord button. The second card shows only when
`site.link.discord` holds a link.

Loupe also sends the tester one welcome email for each redeemed link. The email
goes out when the address of the tester is verified. A tester who redeems a link
before verifying gets it at verification, by the emailed link, a social sign-in,
a social link, the `app:user:verify` command or the admin form. The email
promises free access, so Loupe sends it only when the comp was granted. A link
that was redeemed before this release gets no email. If the queue write fails,
a sweep retries every 15 minutes. You can run that sweep by hand with
`app:billing:send-due-beta-welcome-emails`. See [Commands](../reference/commands.md).

The list shows each link as unused, used by an account on a date, or revoked on
a date. Revoke an unused link with its **Revoke** button. A used link cannot be
revoked. The admin page of a tester's account says "Beta tester since" with the
date, and shows the note.

## Agent outbox

`/admin/outbox` lists every undelivered agent event on the
instance, with its type, attempt counts and next-retry times. See
[Failed messages and the outbox](../operating/failed-messages.md).
