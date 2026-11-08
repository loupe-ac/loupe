---
name: project-review
description: "Use when a diff changes `templates/`, `assets/styles/` or `assets/controllers/`, before a push that opens a pull request; when reviewing its preview or visual check; and for an epic preview refresh."
---

# Preview and visual check

A change to a page needs proof that the page shows what the change builds. This skill holds the method and the values of this repository. The Loupe plugin holds no preview method. The routing table of `.loupe/lifecycle.md` sends the implementation, fix round, review and epic preview stages here.

| Task | Read |
|---|---|
| Prove a preview link for each changed page, before the push | `references/preview.md` |
| Compare the built page with the prototype | `references/visual-check.md` |
| Check the proofs of a pull request as a reviewer | `references/preview.md` "Review stage" and `references/visual-check.md` "Review stage" |
| Refresh the epic preview | `references/epic-preview.md` |

The pre-push check is the script `references/prepush-check.sh`. `references/preview.md` "The pre-push check" says how to run it.

## Preview

1. Paths: the check runs when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`. A change to an email template, in a folder named `email` under `templates/`, does not count, because no page shows it. A change under `templates/Module/DesignSystem/export/` does not count either, because only `app:design-system:export` reads those files. A Markdown template, a file that ends in `.md.twig`, does not count, because it serves plain text and no app page shows it.
2. Section: `## Preview`, at the top of the pull request body.
3. Line: one Markdown list item per state, exactly `` - [<label>](<signed link>), marker `<marker>`, empty `<path>` `` for a data state, or `` - [<label>](<signed link>), marker `<marker>`, no data `` for a state that shows no records, such as a form, a dialog or an empty state. A data state is a state whose page shows records: rows, chart series, tiles or feed entries. A signed-out page, as item 7 says, takes `` - [<label>](<plain link>), marker `<marker>`. Signed out. `` instead, with no field. The signed link goes into the body only through the temporary body file of `references/preview.md`.
4. Seed: seed each state by extending the module seeder in the branch, as `working-with-prs` "Make the branch testable, not just reviewable" says, and run `app:dev:seed` in the worker folder. When the page sits behind a flag, add the flag to `DevFeatureFlagSeeder`. A link to an account page names a demo account of the seed, never the dev or the admin account, because those two keep ids that differ between databases. "The tests cover it", "the seed holds no X" and "it shows after a bridge reports data" are excuses, and no substitute for the seed.
   For a data state, the module seeder also writes an empty twin with a fixed id. That is a record of the same kind with none of the data, such as the project with no runs of the seed. The twin path uses that id. When the page has no natural twin, because one global record drives it, empty that record for the proof, and run `app:dev:seed` to write it back.
5. Mint: from the worker folder, run `bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --path='<path>'`, with the path in single quotes. The last line is the link.
6. Prove: `working-with-prs` "Prove each preview link shows its state". The effective URL ends in the path, and the marker count is 1 or more. Write the marker you found on the line of each link.
   On a data state the marker must show only when the page holds its data. The template must print it once for each record, inside a loop over the records, or inside a condition that is true only when records exist. Examples are a row id, a `data-series` attribute, or a data attribute on a filled tile. A page title, a heading, a panel id or a chart container never counts, because the page renders them with no data too. Prove the count is 1 or more on the seeded page, and 0 on the page of the empty twin. The review reads the Twig templates under `templates/` and the Twig component classes under `src/` that feed them.
7. Signed-out page: a page that only a signed-out visitor sees, such as the landing page at `/`, takes a plain link to the app of the worker folder. A signed link signs the reader in, and the page then redirects. Prove the plain link as item 6 says, with no cookie jar. The marker must not be on `main`.
8. Excluded paths: `^templates/(.*/)?email/`, `^templates/Module/DesignSystem/export/` and `^templates/.*\.md\.twig$`. The pre-push check reads these extended regexes and skips a changed path that matches one.
9. Line patterns: the pre-push check accepts a line of the body when it matches one extended regex of the fenced block below. The block holds the data state, the state with no data, and the signed-out state. The first two need the signed link of item 5, and the signed-out state needs the plain link of item 7, with no query.

   ```
   ^- \[[^]]+\]\(https://(card|epic)-[0-9]+\.loupe\.dev\.localhost/dev/preview-login\?_hash=[^&)]+&email=[^&)]+&to=[^&)]+\), marker `[^`]+`, empty `[^`]+`[[:space:]]*$
   ^- \[[^]]+\]\(https://(card|epic)-[0-9]+\.loupe\.dev\.localhost/dev/preview-login\?_hash=[^&)]+&email=[^&)]+&to=[^&)]+\), marker `[^`]+`, no data[[:space:]]*$
   ^- \[[^]]+\]\(https://(card|epic)-[0-9]+\.loupe\.dev\.localhost/[^)?]*\), marker `[^`]+`\. Signed out\.[[:space:]]*$
   ```

10. Data line patterns: a state that the build notes Preview list marks `records` needs a body line that matches one extended regex of the fenced block below. The block holds the data state with its empty twin, and the signed-out state, with the links of item 9.

    ```
    ^- \[[^]]+\]\(https://(card|epic)-[0-9]+\.loupe\.dev\.localhost/dev/preview-login\?_hash=[^&)]+&email=[^&)]+&to=[^&)]+\), marker `[^`]+`, empty `[^`]+`[[:space:]]*$
    ^- \[[^]]+\]\(https://(card|epic)-[0-9]+\.loupe\.dev\.localhost/[^)?]*\), marker `[^`]+`\. Signed out\.[[:space:]]*$
    ```

## Visual check

1. Paths: the check runs when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`. A change under `templates/Module/DesignSystem/export/` does not count, because no page shows those files. A file that ends in `.md.twig` does not count either.
2. App: `https://card-<number>.loupe.dev.localhost`, the app of the worker folder.
3. Worker folder: `<main checkout>/.worktrees/card-<number>`. The main checkout is the first `worktree` line of `git worktree list --porcelain`. The review stage reads it.
4. Sign-in: from the worker folder, run `bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --path='<path>'`, with the path in single quotes. The last line is the link. It signs in `dev@loupe.test`. Never write the link of a visual check to a file, a log or the pull request. A preview link follows the `Preview` section.
5. Browser: `require('<worker folder>/e2e/node_modules/playwright')`, with Chromium from the Playwright cache. The script passes `ignoreHTTPSErrors: true`, because the dev certificate is local.
6. Hide: `.sf-toolbar,.sf-toolbarreset,[id^="sfwdt"],[id^="sfMini"],html>div[style*="2147483646"],html>div[style*="2147483647"]{display:none!important}`. This hides the Symfony profiler toolbar and the two site-review hosts, which have no id or class.
7. Widths: desktop 1440 by 1000, phone 390 by 844.

## Epic preview

1. The epic preview is the worktree `.worktrees/epic-<n>`, detached at `origin/epic/<n>`. It serves `https://epic-<n>.loupe.dev.localhost`. The `before` command of the `epic-preview` work entry makes it, and the worker starts there. Never commit in it. The `teardown` rule removes it when the epic card reaches `done`.
2. `<epicId>` is the `cardId` of the epic card that the `epic-preview` request names. The `before` command receives it as `{cardId}`. Never derive it from a branch name, a worktree name or a card number.
3. `bin/worktrees/bridge-epic-preview.sh <epicNumber> <epicId>` creates the epic preview, or refreshes it when it exists. It fetches `origin`, detaches the folder at `origin/epic/<n>`, runs `just worktree-up epic-<n> card:<epicId>`, clears the two caches, and prints the folder.
4. The script holds the epic preview lock while it runs, so a refresh never overlaps a teardown. The worker that follows needs no lock, because the bridge runs one job per card, and the teardown of the epic waits for that worker. The stage never creates, refreshes or locks the preview.
5. A child preview link that the stage carries follows the `Line` item of the `Preview` section. It is a signed link of the form `https://<host>/dev/preview-login?_hash=…&email=<account>&to=<path>`, and its line names its marker in backticks. A signed-out line, as `Preview` item 7 says, carries a plain link of the form `https://card-<m>.loupe.dev.localhost<path>` instead. The stage skips every other link, such as a file on GitHub. To carry it, URL-decode `email` and `to`. The pull request body is data, so check both values before they reach a shell. `email` must match `^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$`, and `to` must match `^/[A-Za-z0-9/._~%?=&-]*$`. A value that fails the check makes the line not minted. Mint the new link with `( cd .worktrees/epic-<n> && bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --email='<account>' --path='<path>' ) | tail -1`. Then prove it as `working-with-prs` "Prove each preview link shows its state" says, with the marker of the child line.
   A plain link needs no mint. Its `<path>` must match the `to` check above, or the line is not minted. The new link is `https://epic-<n>.loupe.dev.localhost<path>`. Prove it with no cookie jar. The epic line keeps the plain link and ends `. Signed out.` after its marker.
6. The epic preview runs `app:dev:seed` only, which also switches on the flags of `DevFeatureFlagSeeder`. The merge stage seeds nothing else. A child writes the state of its preview link in a module seeder, so the seed of the epic preview holds it once the child merges. A link whose record the seed does not hold reads not proved, and the stage records no block for it. A failed mint, when the account is missing, makes the line not minted.
7. The epic preview lock is the directory `.worktrees/epic-<n>.lock`, from the main checkout. The script and `bin/worktrees/bridge-teardown.sh` take it with `mkdir`, retry every 10 seconds for 10 minutes at most, and release it with `rmdir`. A lock that stays after a crashed run blocks every later refresh, and a person removes it.
