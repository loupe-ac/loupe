---
title: "Artifact tools"
description: "The five loupe MCP tools that read and archive stored design artifacts, and how a worker uses them."
---

An artifact is a set of prototype files that an interactive session stored in the
Artifacts library of a project. A worker of any harness reads an artifact
through five MCP tools of the `loupe` server. The tools read through the same
token and the same project binding as the document and card tools.

The tools exist only while the feature flag `artifacts.enabled` is on. It is
off on a new install. While it is off, the tool list hides the five tools, and a
direct call returns "Artifacts are switched off on this instance."

A worker reads artifacts and does not store them. A person stores a new version
from an interactive session with `loupe artifact push`, which compiles a Design
canvas to a plain page first. See "Pushing a Design canvas" in
`docs/using/artifacts.md`.

## The tools

| Tool | What it does |
|---|---|
| `artifact_list` | Lists the artifacts of the project, newest first, with the latest version number of each. It hides archived artifacts unless `includeArchived` is true. It pages with `page` and `perPage`, and returns `hasMore`. |
| `artifact_get` | Describes one artifact. It lists every version with its notes, its file count and its size, and lists the files of one version. It also returns `pageLink`, a full web address that opens the entry page of that version. The link works for one hour and needs no sign-in. The version defaults to the latest. |
| `artifact_read` | Reads one file by its path. The version defaults to the latest. |
| `artifact_archive` | Archives an artifact. Nothing is deleted, and its files stay readable. |
| `artifact_unarchive` | Restores an archived artifact. |

`card_get` lists the artifacts that a card links, each with its `artifactId`.
Pass that id to `artifact_get`.

The implementation worker reads every page of a linked artifact before it writes
its plan, and the plan maps each part of the prototype to the code that builds
it. A child of an epic reads the artifacts of its parent card. A sub-agent that
writes a template or a stylesheet reads the pages of its own task with
`artifact_read`. The review stage checks the diff against the same map, and
fails a part of a page that the map omits.

## What `artifact_read` returns

1. A text file comes back as text. This covers HTML, CSS, JavaScript, SVG, JSON and Markdown.
2. A PNG, JPEG, GIF or WebP image comes back as MCP image content.
3. Any other binary file is refused. The message names its path, its content type and its size.

The tools return no MCP resources. Every harness calls tools, and a resource read
would need support that not every harness has.

## Access

A token reaches the artifacts of its own project only. An id that does not exist
and an id in another project give the same "not found or not accessible" answer.
Archiving and restoring record an audit event when the state changes. Archiving
an archived artifact changes nothing.
