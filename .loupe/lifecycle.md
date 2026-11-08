# Lifecycle profile

The Loupe stage skills read this file. It holds the routing table of this repository. The rules live in `AGENTS.md` and the repository skills.

## Routing

### Tech design

Load skill `project-tech-design` before you write or revise the tech design.

Load skill `project-deploy` when you write the deploy notes.

### Implementation

Load skill `working-with-prs` before the gate, a push or a pull request.

Load skill `project-deploy` when you compare the diff with the deploy notes.

Load skill `project-review` when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`, after the build and before the push.

### Fix round

Load skill `working-with-prs` before the gate, a push or a pull request.

Load skill `project-deploy` when you compare the diff with the deploy notes.

Load skill `project-review` when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`, before the push.

### Review

Load skill `project-review` when the diff changes `templates/`, `assets/styles/` or `assets/controllers/`, before you judge the preview and visual check of the pull request.

### Merge

Load skill `working-with-prs` before you merge a pull request.

### Epic preview

Load skill `project-review` when you refresh the epic preview.
