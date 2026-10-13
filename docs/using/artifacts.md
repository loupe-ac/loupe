---
title: "Artifacts"
description: "Store the files of a prototype in a project, and switch the artifact library on."
---

An artifact is a set of files that an agent made, such as a prototype or a
design export. Each project keeps its own artifacts. Every time you store files
again for the same artifact, Loupe saves them as a new version and keeps the
old ones.

Artifacts are behind the `artifacts.enabled` feature flag, and the flag ships
off. While it is off, the store route and every artifact page answer 404, and
the sidebar shows no Artifacts link.

## Turning artifacts on

Open the flags page at **`/admin/feature-flags`** and switch `artifacts.enabled`
on. The change needs no restart. See [The admin area](admin.md).

The install wizard writes the flag rows on a fresh install, and a database
migration writes them on an instance that upgrades. A missing row reads as off.

## Limits

Two more flags on the same page set the limits for one stored version.

| Flag | Default | What it limits |
|---|---|---|
| `artifacts.max_version_bytes` | 5 MiB | The total size of all files in one version |
| `artifacts.max_files` | 50 | The number of files in one version |

A request that goes over a limit gets a 422 answer and stores nothing. The web
server refuses a request body of more than 8 MiB, so Loupe caps
`artifacts.max_version_bytes` at 7 MiB and `artifacts.max_files` at 100. A
higher value in a flag has no effect.

Each file path must be relative. It has no empty segment, no `.` or `..`
segment, no backslash, and no control character. It has at most 1024 characters.
Two files of one version cannot share a path.

## Storing a version

Send a `POST` request to `/api/projects/{project}/artifacts`, where `{project}`
is the id or the slug of the project. The request is `multipart/form-data` with
these fields.

| Field | Meaning |
|---|---|
| `files[]` | One file for each entry. Its content type comes from the part. |
| `paths[]` | The path of each file, in the same order as `files[]` |
| `name` | The name of a new artifact |
| `artifactId` | The id of an existing artifact, to store a new version of it |
| `sourceLink` | Optional. Where the files came from. |
| `notes` | Optional. What changed in this version, up to 2000 characters. |
| `card` | Optional. The id or number of a card to link the artifact to. |

Send `name` for a new artifact, or `artifactId` for a new version. The request
needs an agent token of the project owner. The `X-Loupe-Session` header may
carry the session id of the agent.

An agent session that runs as a worker cannot store. Only an interactive session
stores a version. A worker gets a 422 answer that says so.

The answer is a 201 with `artifactId`, `versionNumber`, `path` and
`versionPath`.

## Storing from the command line

Run `loupe artifact push` to store files from disk. The command sends the files
itself, so an agent writes only the command and not the content of each file.

```bash
loupe artifact push review-page.html --name "Review page" --card 611
loupe artifact push prototype/ --artifact 01a12d27-5bd5-73d0-aa2e-8d3cfc2e3337 --notes "Moved the prices into a table"
```

| Flag | Meaning |
|---|---|
| `--name` | The name of a new artifact |
| `--artifact` | The id of an existing artifact, to store a new version of it |
| `--card` | Link the artifact to a card, by its number or its id |
| `--source-link` | Where the files came from, such as a design canvas link |
| `--notes` | What changed in this version |
| `--index` | The artboard that becomes `index.html` when the folder holds several canvases. See "Pushing a Claude Design canvas". |
| `--out` | Write the compiled files to an empty or new folder, and store nothing. See "Pushing a Claude Design canvas". |
| `--project` | The project id. Without it the command reads `.loupe.yaml`, then the one project your login covers. |

Pass `--name` or `--artifact`, never both. A file is stored under its base name.
A folder is stored with the path of each file relative to the folder, and the
command skips a `.git` folder. The command refuses an empty folder, a file that
is not a regular file, and a push of more than 8 MiB.

The command sends the id of the session that runs it, read from
`LOUPE_SESSION_ID`, `CLAUDE_CODE_SESSION_ID` or `CODEX_THREAD_ID`. A worker run
cannot store, so run the command from an interactive session. It prints the
artifact id, the version number, the stable link and the link of that version.

## Pushing a Claude Design canvas

A Claude Design canvas, a `.dc.html` file, shows as a broken page outside
Claude Design. When the pushed folder holds a canvas, the command compiles it
to a plain page before it stores anything. It runs the script of the canvas on
your machine, so you install nothing. The folder then holds no canvas file and
no `support.js`, and the artifact holds the compiled page.

A canvas with a script keeps its clicks and its typed input. The compiled page
first shows the page that the build drew, so a reader that runs no script sees a
full page. The page also holds one inline script. That script holds the logic
of the canvas, a small Loupe redraw runtime, and the template as a render
function. When a click or a keystroke calls `setState`, the page draws again and
keeps the focus and the caret of a text field. An `onChange` on a text field
runs on each keystroke, as in React. Timers and `componentDidMount`
also run. A canvas with a script and no event, timer or mount hook compiles to
plain markup, with no script.

One canvas becomes `index.html`. A link to a canvas of the folder, such as
`href="Pricing.dc.html"`, points at its compiled page. Other files of the
folder go through unchanged.

A folder can hold several canvases, one for each artboard. Pass
`--index <artboard>` to name the one that becomes `index.html`, at the root of
the artifact, so the viewer opens it first. Each other canvas becomes
`<Name>.html` beside its source. When the index canvas sits in a subfolder, the
command changes the relative links of its page, so each link still reaches
the same file. An interactive page also changes each link that a value of its
script gives, every time it redraws. The value is
the path of a canvas with or without `.dc.html`, such as `site/Home`. It can
also be a base name, such as `Home`, when one canvas only has that name. A name
that matches no canvas, or more than one, stops the command and lists the
canvases. With one canvas the flag must name that canvas. A link to the index
artboard points at `index.html`.

A blob is a file that Claude Design stores outside the canvas, for example an
image. The canvas refers to it as `/_blob/<id>`. Put the file in the folder as
`_blob/<id>.<ext>`, at the root of the folder. The compiler then replaces each
`/_blob/<id>` in an attribute, such as `src`, `href`, `poster`, `srcset` or
`style`, and in a `<style>` block, with a link from the page to that file. A
hole can give the id, such as `/_blob/{{ id }}`. An interactive page then
points the path at the file again at each redraw. The command keeps the blob
files in the artifact.

The compiler stops at what it cannot compile. It stores nothing and prints one
line for each gap:

```text
cannot compile Main.dc.html, line 148: event attribute onclick is not supported. Run again with --out <folder> to get the pages with each gap marked.
```

These are gaps today:

- An event attribute whose value is not one plain hole, such as
  `onclick="go()"`, and any event attribute in markup that
  `dangerouslySetInnerHTML` fills in.
- An event attribute of a page that has another gap. The page then compiles
  with no script, so fix the other gap first.
- An embedded artboard, which is any `<dc-...>` tag.
- One of these two features in markup that `dangerouslySetInnerHTML` fills in.
  Such markup cannot hold a `dangerouslySetInnerHTML` of its own.
- A link to a canvas that the folder does not hold.
- A `<sc-else>` tag.
- A `/_blob/` path that no file of the folder answers. The file is
  `_blob/<id>.<ext>` or `_blob/<id>`, and two files with one id also count as
  a gap.
- A `/_blob/` path that is not a plain id, such as one with `..` or a `/` in it.
- A `/_blob/` path in text or in a `<script>`, and one that a script value fills
  in.
- A file of the folder with the name of the compiled page, such as
  `index.html`. The page is then written to `index.compiled.html`.
- More than one canvas file in the folder, when the push has no `--index`.
- A hole that is not a plain path, such as `{{ a + b }}`.
- Canvas text left in the compiled page.
- Canvas text, such as `{{` or a raw `/_blob/` path, in a text file beside the
  canvas. A relative link such as `../_blob/x.png` is no gap.
  The command copies that file and marks each gap in its comment syntax:
  `/* loupe:gap ... */` in CSS and JavaScript, the HTML comment in HTML,
  SVG, XML and Markdown, and a plain `loupe:gap ...` line in any other file.

Run the command again with `--out <folder>` to see the pages. It writes the
compiled files to the folder and stores nothing. The folder must be empty or
new. Each gap has a comment, `<!-- loupe:gap <feature>: <detail> -->`, before
the markup it covers. The command exits with an error when a gap exists, even
though it wrote the files. Finish each gap by hand, remove its comment, and push
the folder. A push that still holds `loupe:gap` in a text file is refused, with
or without a canvas.

An embedded artboard stays a gap, also when the folder holds the canvas that it
names. Paste the markup of that artboard by hand.

## The library

When the flag is on, the sidebar of a project shows an **Artifacts** link with
the number of artifacts that are not archived. It opens the library at
`/projects/{project}/artifacts`. Each row shows the name of the artifact, its
newest version, the number of files in that version, and where the files came
from, such as a Claude Design canvas or a claude.ai artifact. It also shows who
stored the newest version, the first card that links to it with the number of
other cards, and when it was stored. The artifact with the newest version comes
first.

An artifact that an agent stored from an interactive session shows
"Claude, interactive session" as the author. Only the owner of the project can
open the library.

The two buttons at the end of a row copy the link of the artifact and archive
it. An archived artifact is hidden. Turn on **Show archived** to list it, and
restore it with the same button.

A card page lists the artifacts that link to the card, under **Artifacts**,
with the newest version of each.

## The viewer

Choose an artifact to open the viewer. The page shows the files of one version
in a frame, under a thin bar with the name of the artifact and its buttons.

| Button | What it does |
|---|---|
| File | Lists the pages of the version, when it holds two or more. Choose one to show it in the frame |
| Open full screen | Opens the artifact in its own window, with only the bar and the frame |
| Copy link | Copies the link of the page you see |
| More actions | Opens a menu with the items below |

| Menu item | What it does |
|---|---|
| Download | Gives you the files of the version you see, as a zip file |
| Archive | Archives the artifact, or restores an archived one |
| Linked cards | Lists the cards that link to the artifact. Choose one to open it. |
| Revision notes | Lists every version with its notes. Choose one to open it. |
| Details | Names who stored the version, its source, and how the frame is sandboxed |

The link `/projects/{project}/artifacts/{artifact}` always opens the newest
version. The link `/projects/{project}/artifacts/{artifact}/versions/{number}`
opens one version and never changes. A link that an agent returns after a store
is one of these two.

The frame opens `index.html`. When the version has no `index.html`, it opens
the first HTML file, and then the first file. Under the frame, a line shows who
stored the version and when. When the artifact has a source link, the line also
shows the link and the date of the store.

An archived artifact still opens. Choose **Unarchive** in the more actions menu
to list it again.

## Limited preview

Loupe shows the files in a sealed frame. A script in a prototype runs, but it
cannot read your session or call Loupe as you. Some features that need a normal
web origin may not work, such as storage in the browser. The viewer shows a
band that says so. Choose **Dismiss** to hide the band. It returns when you
open the page again.

The address keeps the page you chose, as `?file=<path>`. Open full screen and
Copy link carry it, so a link opens the same page for the person who gets it.
An address that names a file the version does not hold shows a not found page.

Each file comes from a link that Loupe signs and that expires after one hour.
Reload the page to get a fresh link.

## Your data

An account data export holds an `artifacts.json` file. It lists each artifact of
your projects with its versions and files. A text file appears as a string. Any
other file appears as base64. Deleting a project, or your account, deletes its
artifacts.
