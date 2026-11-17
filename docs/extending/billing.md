---
title: "Billing"
description: "Optional, off by default. Stripe subscriptions behind the billing.enabled flag."
---

Nothing instantiates the Stripe client until `billing.enabled` is on, so an
instance with no Stripe account is not merely unbilled — the integration is
never constructed.

| Variable | Purpose |
|---|---|
| `STRIPE_SECRET_KEY` | API key |
| `STRIPE_WEBHOOK_SECRET` | Verifies inbound webhooks |

`/admin/status` reports whether both are set once the flag is on.

An empty `STRIPE_WEBHOOK_SECRET` makes `/webhooks/stripe` refuse every delivery
with 503. The endpoint also answers 429 after 120 requests a minute from one
client address. Stripe retries both answers.

Checkout and the customer portal open on `checkout.stripe.com` and
`billing.stripe.com`, and the production Content Security Policy (CSP) lets a
form send people to those two hosts. A Stripe account with a custom domain
opens them on that domain instead. Set `STRIPE_CUSTOM_DOMAIN_ORIGIN` to its
origin, such as `https://payments.example.com`. Without it, the browser blocks
Subscribe and Manage billing, and only the browser console names the cause.
Leave it unset when your account has no custom domain.

## What it adds

The *Billing* tab of the account settings, at `/account/billing`, offers the
plan. `/billing/checkout` starts a Stripe Checkout session,
`/billing/checkout/success` returns from it, and `/billing/portal` sends a
subscriber to Stripe's own management portal.

Trials end on a schedule rather than on a request: `app:sweep-ended-trials`
disables expired trials and cancellations past their period, and sends survey
emails. Like everything scheduled, it runs only if a worker is consuming — see
[What runs in production](../architecture.md).

An account disabled this way meets a paywall that still lets it reach the
subscribe page, and — when a registration cap is full — the waitlist.
