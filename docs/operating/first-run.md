---
title: "First run"
description: "Creating the first administrator, and why sign-up is closed until you do."
---


Set `INSTALL_TOKEN` **before** the first deploy. The install wizard at
`/install` is how you create the first administrator, and in production it
**fails closed**: with `APP_ENV=prod` and no token configured it returns 404
outright. A forgotten variable locks the wizard rather than exposing it. Open `/install`, and paste the token into the form that it shows. The app
remembers it for the rest of that session. Never put the token in the URL,
because the access log and the browser history would keep it.

If you forget it you are not locked out, but the fix is a shell rather than a
browser — see [Recovering an instance](recovering.md).

Sign-up is closed until the instance is installed: `/register`, the OAuth
sign-up branch and `/waitlist` all refuse to create the **first** account, so a
missing `INSTALL_TOKEN` cannot leave a fresh instance to whoever finds it first.
Registration is additionally gated on the `registration.enabled` feature flag,
which the wizard seeds and the admin area can toggle at any time. When the flag
row is absent — an instance upgraded from a version that never seeded it, or one
recovered entirely from the shell — registration stays open, so an existing
instance keeps behaving as it did.

