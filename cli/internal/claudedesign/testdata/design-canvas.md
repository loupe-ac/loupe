# Design canvas fixture

The folder `design-canvas/` holds a copy of the Design canvas "Loupe onboarding redesign", https://claude.ai/artifact/2cQoUK4XGFpaMLmj9g67bz.

- An owner session copied it on 2026-10-19 with the `Artifact` tool, from version `1792446354-3b72`.
- Each file is the artifact file, byte for byte, at the same path. The canvas files, `canvas.json` and the installed design system under `project/ds/loupedesignsystem_686f56/` are all present.
- The copy leaves out the files of the artifact type: `artifact-type/`, `index.html` and `SKILL.md`.
- Each artboard loads `./support.js`, and the artifact holds no such file.

A bridge worker has no `Artifact` tool, so it reads this copy.
