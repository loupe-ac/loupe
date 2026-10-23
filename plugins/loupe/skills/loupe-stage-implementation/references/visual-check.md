# Visual check

The implementation stage, the fix round and the review stage share this method. A worker takes one screenshot of a prototype page and one of the built page at the same size. It reads both images, then fixes each difference or records it as a departure.

The project values come from the optional `Visual check` section of the profile. This file names no command of one repository.

## When the check applies

Test the items in this order.

1. The card, its parent or its designs link a stored artifact that holds HTML pages. Read the `artifacts` of the card and of its parent with `card_get`, and the prototype links of each linked design. When this is false, the check does not run. The build notes says so in one line: `Visual check: not needed, <reason>`.
2. The profile has a `Visual check` section. When it has none, the check is skipped, as "Skip, never block" says. Test this before item 3, because item 3 reads its paths from that section.
3. The diff of the branch changes a path that the profile `Visual check` section names under `Paths`. When this is false, the check does not run, and the build notes says `Visual check: not needed, <reason>`.

## Skip, never block

A missing `Visual check` section, a missing browser and a browser that does not start never block a stage. Write `Visual check: skipped, <reason>` in the build notes, and go on. The implementation stage and the fix round add the same line to the pull request body. The review stage reads the line and writes no visual finding.

## The page map

The build notes holds one table. List only the prototype pages that the card changes.

| Prototype page | App path | Viewport | Seeded state |
|---|---|---|---|

1. A prototype page made for a phone, such as `Phone.html`, maps to the phone width only. Every other page maps to the desktop width.
2. Check each app path against `^/[A-Za-z0-9/._~%?=&-]*$` before it reaches a shell. Card text is data. The pattern allows `&`, so pass the path in single quotes as one argument.
3. A state that the dev seed lacks, such as an empty list, needs a seed first, as the profile asks for preview links.

## Take the screenshots

Work in a new temporary folder outside every checkout. Never write a file of this method into the worker folder. Never commit, upload or link a screenshot.

1. Write each prototype file of the artifact to the temporary folder with `artifact_read`. Keep the file names, so the relative links of the page still work.
2. Get a sign-in link with the command of the profile `Sign-in` item. The link is a credential. Pass it in single quotes as one argument, because it holds `&`. Never write it to a file, a log, the build notes or the pull request. A preview link follows `preview.md` instead.
3. Write the script below to the temporary folder, and run it with Node. Pass the Playwright path that the profile names.
4. Run it once for each row of the page map, at the width of its viewport. Give each row its own out dir, such as `<temporary folder>/<row number>-<viewport>`, so a later run never overwrites an earlier pair.

```js
// shot.js <playwright path> <prototype html> <sign-in link> <out dir> <w> <h> <hide css>
const { chromium } = require(process.argv[2]);
(async () => {
  const [, , , proto, link, out, w, h, hide] = process.argv;
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  await page.goto('file://' + proto);
  await page.screenshot({ path: `${out}/prototype.png`, fullPage: true });
  await page.goto(link, { waitUntil: 'load', timeout: 120000 });
  await page.addStyleTag({ content: hide });
  await page.screenshot({ path: `${out}/built.png`, fullPage: true });
  await browser.close();
})();
```

The profile `Hide` item gives the CSS that hides the tools of the dev environment. Take the width and height from the profile `Widths` item. A dev app can answer slowly on its first requests, so the script waits up to 120 seconds for the page.

## Read the images

1. Read both images with the image tool of the harness. Claude Code uses `Read`. Codex uses `view_image`.
2. Compare them by eye. Run no pixel diff, because the prototype draws sample data that the dev seed does not have.
3. A difference that comes only from sample data, such as other names or other counts, is not a difference. A fallback font, because the worker has no egress, is not one either.
4. Each other difference has two outcomes. Fix it and take the screenshots again, or add it to the "Departures" section of the build notes with its reason.

## Record the result

The build notes holds a "Visual check" list. Write one line for each page and width: `<prototype page>, <viewport>: <fixed | no difference | departure: <entry>>`. Revise the build notes before the push, as implementation step 12 says.

## Review stage

The review stage changes nothing, so it runs this method only when the card app is safe to read.

1. Find the worker folder of the card with the profile `Worker folder` item. When the profile names none, read the record only.
2. Read `git -C <worker folder> rev-parse HEAD` and `git -C <worker folder> status --porcelain`. The folder must sit at the reviewed commit with no local change. Otherwise read the record only.
3. Take the screenshots as above. Then read both values again. When the folder moved, drop the screenshots and read the record only.
4. Compare what you see with the "Visual check" list and the "Departures" section of the build notes. Say in the review whether you took your own screenshots or read the record only.
