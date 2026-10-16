---
title: "Billing"
description: "Where a user sees their plan, subscribes, and manages payment."
---

The *Billing* tab of the account settings, at `/account/billing`, shows your
plan. The tab appears only when the `billing.enabled` flag is on. An instance
with billing off has no tab, and the app is free to use.

The tab shows the status of your account: a trial with the days that remain,
an active subscription, a failed payment, or an expired trial. It also shows the
price of the plan.

- **Subscribe** opens a Stripe Checkout page. Stripe takes the card details, and
  Loupe never sees them.
- **Manage payment details** opens the Stripe portal. Use it to change a card,
  fix a failed payment, or cancel.

When the trial of an account ends, the pages of your projects send you to this
tab until you subscribe. The account settings, the data export, account
deletion and the sign-in pages stay open. When the instance is full, the tab
offers the waitlist in place of the **Subscribe** button, or says that no spot
is free when the waitlist is closed.

Operators enable billing as [Billing](../extending/billing.md) says.
