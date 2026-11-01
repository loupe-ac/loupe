---
name: project-e2e
description: "Use when writing, fixing, debugging or running Playwright e2e tests under `e2e/`, starting or stopping the e2e target, or reading the e2e CI checks."
---

# E2E Tests

## General rules

- The eight `e2e-*` CI checks gate the suite: `e2e-chromium`, `e2e-chromium-2` to `e2e-chromium-6`, `e2e-rest` and `e2e-global-flags`. Never run the whole suite on this machine. Push and read the checks. Locally the suite is slower and destructive. It also fails for reasons that belong to the environment and not to the diff. One spec stays allowed while you debug it: `just e2e tests/<area>/<spec>.spec.ts`. The `.agents/hooks/no-full-e2e.sh` hook refuses a full run and lets a named spec through.
- CI splits the suite across eight runners, and `E2E_SHARD` picks the part. `chromium` runs that project alone, and six legs split it per test with `--shard=k/6`. `rest` runs admin and the destructive chain, with chromium and global-flags dropped from it. `global-flags` runs that project alone. Unset runs everything. The `--shard` option of Playwright cannot split the whole suite, because it filters top-level projects only, and `install-reset` is the one top-level project here.
- Each chromium test is its own unit. The project is `fullyParallel`, so two tests of one file can run in two workers at once. A test cannot read state that another test of the file made. A file whose tests depend on their order opts out with `test.describe.configure({ mode: 'default' })`.
- A run with `E2E_BASE_URL` set by hand to a sibling worktree wipes the dev data of that worktree, and the automatic repair does not run. Read `references/sibling-worktree-run.md` before such a run. It needs `MAILPIT_URL` too, and `bin/worktrees/worktree-bootstrap.sh` after.
- `just e2e-up` creates the disposable target of the suite: an `app_e2e` database, and an nginx sidecar at `e2e.<project>.dev.localhost` that serves this checkout. `just e2e` defaults there, and refuses to start when the target is not up. Tear it down with `just e2e-down`.
- Never point the suite at the dev host. The suite is destructive by design. The `install-reset` project truncates every table. `trial-end-lifecycle` flips global feature flags and disables every expired-trial account. One run against `loupe.dev.localhost` wipes your development database.
- Never fix a failing test by manipulating the database, such as resetting passwords or deleting rows. A test that needs a specific database state must create that state. A fix that needs a one-time database operation breaks again on the next fresh environment.

## Turbo Drive navigation

Turbo Drive performs form submissions as XHR, and pushes state with `history.pushState`. So `page.waitForURL(pattern)` waits for a `load` event that never comes. Use `expect(page).toHaveURL(pattern)`, which polls.

After a POST that redirects to a different page, assert that an element of the destination page is visible. That is safer than an assertion on the URL. When the redirect returns to the same URL, `toHaveURL` resolves immediately, so assert the updated form value instead (see below).

```typescript
// ✗ fails with Turbo — URL already matches before XHR completes
await page.waitForURL(/\/some\/path/);

// ✓ polls for URL without requiring a load event
await expect(page).toHaveURL(/\/some\/path/);

// ✓ even better — assert visible content from the destination page
await expect(page.getByRole('button', { name: 'Submit' })).toBeVisible();
```

## Forms that redirect to the same URL

This covers admin edit pages and any in-place action that re-renders or returns to the current page, for example a review verdict that PRGs back to the review page. The page never left, so `toHaveURL` and "an element is still visible" resolve immediately. The assertion passes whether the submit succeeded, failed validation, or is still in flight, and under parallel load it races the POST. Wait for a post-submit content signal instead: a success flash, the new row, or an updated field value.

Admin edit controllers are the main case, because they redirect back to the same edit URL on success. Always follow `toHaveURL` with a second assertion for the updated value. Only the re-render of the persisted data after the redirect can make that assertion true.

```typescript
await page.getByRole('button', { name: 'Save' }).click();
await expect(page).toHaveURL(/\/admin\/foos\/\d+\/edit(\?|$)/, { timeout: 15000 });
// Wait for the edit page to re-render with the new value before navigating away;
// without this the toHaveURL above resolves immediately (we were already on the edit URL).
await expect(page.locator('select[name="foo[status]"]')).toHaveValue('active', { timeout: 15000 });
await page.goto(`/admin/foos`);
```

Match the assertion to the field type:

- `toHaveValue(newValue)` for inputs and selects.
- `toBeChecked()` / `not.toBeChecked()` for checkboxes.
- `getByRole('spinbutton', …).toHaveValue(…)` for integer fields.

## Symfony Web Debug Toolbar

The `X-Playwright: 1` header disables the toolbar (WDT) for all Playwright requests. Do not add `waitForLoadState('networkidle')` workarounds. Keep `suppressToolbar(page)` in `fixtures.ts`, because it covers cached responses that came without the header.

## The Mercure hub

CI refuses the hub, so a page that opens it retries and slows the app. A spec imports `hubStubbedTest` from `fixtures.ts` as its `test`, and `createTest` builds on it. A spec that reads live updates imports `test` from `@playwright/test`, and opens the real hub.

## Parallelism and PHP sessions

Each spec file that needs a login gets its own dedicated user through `createTest`. When two files share one user, a password or `displayName` change in one file invalidates the session of the other. Mailpit is never cleared. A stale email from an earlier run can send the test to the host of another worktree, and the result is a wrong-host 404 that reads as an app bug. `test.use({ storageState: {} })` stops the registration of the user, with no error.

`playwright.config.ts` runs each `chromium` test as its own unit on 4 workers. A spec file whose tests share one fixed account opts out with `test.describe.configure({ mode: 'default' })`. Some specs run in a `workers: 1` project: a spec that changes a global flag, or one that shares a fixed account with another file. The config comments say which project. Put a new spec of that kind in one of those projects, and never in `chromium`. Nobody has proven that two branches can gate at the same time on one machine, because every worktree shares the one `php-fpm` container.

Read `references/parallelism-and-sessions.md` before you write an authenticated spec, edit `e2e/tests/fixtures.ts`, or assert on mail.

## Mail is async: never resolve "the newest Mailpit message"

`getLatestEmailTo()` is safe only for a per-run-unique address with a single expected message. For everything else, use mark-then-wait. Capture `latestEmailIdWithSubject(...)` before the triggering action, and pass it to `getEmailWithSubject(subject, afterId)`. Mailpit subject search matches a case-insensitive substring. Registration in the fixtures uses this pattern. The authenticated fixture repairs a registered but unverified account through real product surfaces (resend, then verify), and does not fail.

## The suite needs no messenger consumer, and two jobs run inline as a result

`PlaywrightSyncMiddleware` stamps every envelope dispatched during a request that carries `X-Playwright`, and mail is only one of them. So there is no worker to start and none to forget. It is registered under `when@dev` only, so production dispatch stays the same.

A decision on 2026-08-16 accepted a cost. `CancelSubscriptionMessage` and `GenerateDataExportMessage` also run inline, which puts their handlers inside the Doctrine transaction of the request. Account deletion can therefore call Stripe while the transaction is open. A Stripe failure then rolls the deletion back, and does not enter the retry flow of Messenger. A Codex review argued to exclude these two jobs. The decision kept them inline on purpose, because the point was to remove the worker as a variable from e2e.

So a green suite does not exercise the real async path for those two jobs, and it does not catch a bug that lives only there. Cover that path with a functional test. Revisit the exclusion if either job gets behaviour that depends on a committed transaction.

## Guest test scoping

For guest (unauthenticated) flows, put `test.use({ storageState: { cookies: [], origins: [] } })` at describe block level, so that no session cookie comes in. Do not rely on the absence of a `createTest` call. Make the intent explicit.

## `getByLabel` substring collisions

When one label is a substring of another on the same page (`"New password"` and `"Repeat new password"`), `getByLabel('New password')` matches both and throws in strict mode. Always use `{ exact: true }` for a label that can be a substring of another label on the page.

## Selector scoping for nested same-tag elements

The page can nest same-tag elements, such as a `<details>` submenu inside a user-menu `<details>`. Then do not select with positional CSS such as `details > summary` or `header details summary`. Both summaries match, so Playwright strict mode fails. Scope to a stable attribute on the outer element instead. Use `details[data-controller="user-menu"] > summary` for the parent, and `details[data-controller="user-menu"] details > summary` for the nested one. Prefer a Stimulus `data-controller`, a route-bound `id`, or a unique class. Never use tree position.

## Asserting controls inside a closed `<details>`

Accessible-name locators (`getByRole`, `getByText`) match only elements in the accessibility tree. A closed `<details>`, such as a kebab or disclosure menu, hides its contents from that tree. So `row.getByRole('button', { name: 'Delete' })` resolves to 0 elements while the disclosure is closed, although the button is in the DOM. To assert such a control, do one of these:

- Open it first, then assert visibility: `await row.locator('summary[data-controller="user-menu"]').click()`, then `await expect(row.getByRole('button', { name: 'Delete' })).toBeVisible()`.
- Assert DOM presence with a CSS locator, which ignores visibility: `await expect(row.locator('input[name="…"]')).toHaveCount(1)`.

Never conclude that the control is not rendered from a `getByRole` count of 0 before you open the disclosure. The gated control can be present and only collapsed.

## Keep hardcoded copy in sync with the app

User-facing text can change: email subjects, notification bodies, flash messages, UI labels. Any e2e test that hardcodes that string then times out, and gives no clear assertion error. Before you finish a copy change, do these steps:

1. Run `grep -r "old text" e2e/` to find the affected tests.
2. Update Mailpit search queries (`subject:"..."`, `bodyContains` strings), `getByText(...)`, `toHaveText(...)`, `toContain(...)`, and similar assertions.

Email subject changes in `src/Module/*/Notifier/` are the most common source.

## Default-value changes silently break helpers that rely on the default

A helper that creates an entity through a form, and does not set a field, depends on the default of that field. Suppose you change the create-time default: the form `*Request` DTO default, or an entity default that a direct-instantiation handler reads. Those tests then fail by timeout, and give no clear assertion error. The rendered UI branch changes, a different control appears, and the locator never shows up. Before you change a default, `grep` `e2e/` for helpers that create the entity. Set the field explicitly in each helper where the test depends on the old value.

## Run the spec after a locator refactor

You can change selectors, for example `data-*` attribute selectors to `getByRole(...)` / `getByLabel(...)`, or `page.locator(css)` to a semantic locator. Neither `just lint` nor `just phpstan` exercises that change. Only the affected spec validates it: `just e2e tests/<area>/<spec>.spec.ts`. Never declare a selector refactor done from a clean static-analysis run. Playwright role and name resolution depends on the rendered DOM (icon `aria-hidden`, accessible-name composition), and nothing else checks it.

## Dev seed endpoints (manual + visual verification)

For manual or visual verification, for example when you drive the app with the Chrome MCP, open sessions and seed data through the dev-only endpoints. Do not use the full Mailpit registration flow there. All endpoints are gated `#[When('dev')]`, and they are faster because they skip the email round trips.

- `POST /dev/register-and-verify` (form: `email`, `password`, optional `fullName`) creates an already-verified user. Then log in through the `/login` form. Without `fullName`, it derives one from the email, exactly as the client-side suggestion of the registration form does. It accepts and ignores any `username` in the body, so older specs that still post it keep working.
- `POST /dev/seed/document` (form: `title`, `markdown`) seeds a review document for the authenticated user and returns `{"documentId": "…"}`.
- `POST /dev/oauth/access-token` (form: `scopes`, optional `project`) gives the signed-in user an access token, with no consent page. `scopes` is a space-separated list of base scopes, such as `agent mcp` or `site-review`. The handler adds the binding. A scope that needs a project takes the one that `project` names, or every project of the owner. `accessToken` (fixtures.ts) wraps it, and `agentAccessToken` is the `agent mcp` shorthand.

  Use it in any spec that calls an API and tests something else. Do not use it to test sign-in. PHPUnit covers the three flows that issue a token in production, under `tests/Module/OAuth/`. `site-review/widget-oauth.spec.ts` walks the widget popup in the browser.
- `GET /dev/live-review-harness?email=<user-email>` renders a page that embeds the widget by project. It finds or creates the `e2e-harness` project of that user, clears the comments of the project, and adds the origin of the page to the allowed sites. The reviewer signs in through the OAuth pop-up, so there is no token to read and no seeding endpoint. Press **Sign in with Loupe** in the widget, then drive its own UI to make a comment. `site-review/widget-oauth.spec.ts` walks the whole flow. Pass `&keep=1` to skip the purge.

Inside specs, the Playwright helper layer (`createTest`, `registerAndVerify`) stays the path. These endpoints are the fast path for ad-hoc and browser-driven verification. Some specs already use them directly, for example `review/review-loop.spec.ts`.
