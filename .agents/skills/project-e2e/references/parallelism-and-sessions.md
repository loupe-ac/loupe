# Parallelism and PHP sessions

The config default is `fullyParallel: false` on 4 workers, and the `chromium` project overrides it to `fullyParallel: true`. A spec that changes a global flag (`/dev/e2e/feature-flag`, `/dev/billing-state`) must go in a `workers: 1` project in `playwright.config.ts`. So must a spec that shares an account with another file, such as `ADMIN_EMAIL`. In `chromium` it races every file beside it.

Each spec file that needs an authenticated session calls `createTest(credentials)` from `e2e/tests/fixtures.ts` with its own dedicated user. The worker fixture logs in as that user. On the first run, it registers and verifies the user through Mailpit. Each file therefore has its own PHP session. That avoids CSRF token conflicts. It also stops a change to one user (password re-hash, displayName change) from invalidating the session of another file. There is no shared setup phase, because the fixture creates users lazily, on the first run of the file.

Mailpit tests (register, delete account) search by recipient address and do not clear all messages, so they do not race.

## Mailpit is per worktree and never cleared

Assume dirty inboxes. Each worktree has its own sidecar (`MAILPIT_URL`, exported by `just e2e`). A run with an explicit `E2E_BASE_URL` gets the shared instance. A recipient search is not enough when a previous run sent an email with the same subject to that address. Capture the newest matching message id before the triggering action. Then use `getEmailWithSubject(request, address, subject, { afterId })`, so the poll waits for a fresh message. When you get this wrong, the test follows a link from the stale email of another worktree, and gets a wrong-host 404 that looks like an app bug.

## Helpers in `e2e/tests/helpers.ts`

- `registerAndVerify(page, request, credentials)` fills the registration form, polls Mailpit for the verification link, and navigates to it.
- `fetchVerificationUrl(request, email)` polls Mailpit until a verification email arrives for that address, then returns the URL.

## Global setup

`e2e/global-setup.ts` runs once before any worker starts. Use it for one-time prerequisites that all tests depend on. Do not use `test.beforeAll`. Its `{ timeout }` option is unreliable when `test` comes from `createTest`, and workers that race to do the same setup cause flakiness.

To add an authenticated spec file, call `createTest({ email: 'e2e-your-feature@example.com', password: 'e2e_password_123', name: '...' })` at the top of the file. The first run creates the user, and there is no central registry to update.

## Session invalidation

Any controller that writes back to the `User` entity (`displayName`, `password`) changes the serialized user. Symfony then invalidates that session on the next request. Each file has its own user, so the damage stays in that file. Use `test.use({ storageState: {} })` plus a `beforeEach` login in this case:

- A `beforeAll` creates a second context with `workerStorageState` before an authenticated test. That context can leave the server-side session in an unexpected state.

`test.use({ storageState: { cookies: [], origins: [] } })` cuts the `workerStorageState` fixture out of the dependency graph of Playwright, so the user is never registered. With this pattern, reference `workerStorageState` in `beforeEach`. `void workerStorageState` is enough to make the fixture run.

Tests that change the password of the user (like `can change password`) use a throwaway timestamped user from `registerAndVerify`, and not the shared worker user. A test that fails partway through then leaves no broken database.
