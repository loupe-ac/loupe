# Design System artifact fixture

The folder `design-system-artifact/` holds a copy of the Loupe Design System artifact, https://claude.ai/artifact/SMNR4qM5HfUWH4xqMoEFSd.

- An owner session copied it on 2026-10-19 with the `Artifact` tool, from version `1792446353-07f0`.
- Each file is the artifact file under `project/`, byte for byte, at the same path without the `project/` prefix.
- The copy leaves out `project/archived/`, the 57 files of the standalone Claude Design layout. The copy also leaves out the files of the artifact type: `artifact-type/`, `index.html` and `SKILL.md`.

A bridge worker has no `Artifact` tool, so it reads this copy. Copy the artifact again when its files change.
