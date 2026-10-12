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
| `--project` | The project id. Without it the command reads `.loupe.yaml`, then the one project your login covers. |

Pass `--name` or `--artifact`, never both. A file is stored under its base name.
A folder is stored with the path of each file relative to the folder, and the
command skips a `.git` folder. The command refuses an empty folder, a file that
is not a regular file, and a push of more than 8 MiB.

The command sends the id of the session that runs it, read from
`LOUPE_SESSION_ID`, `CLAUDE_CODE_SESSION_ID` or `CODEX_THREAD_ID`. A worker run
cannot store, so run the command from an interactive session. It prints the
artifact id, the version number, the stable link and the link of that version.

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
in a frame, under a thin bar with the name of the artifact and three buttons.

| Button | What it does |
|---|---|
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

Each file comes from a link that Loupe signs and that expires after one hour.
Reload the page to get a fresh link.

## Your data

An account data export holds an `artifacts.json` file. It lists each artifact of
your projects with its versions and files. A text file appears as a string. Any
other file appears as base64. Deleting a project, or your account, deletes its
artifacts.
