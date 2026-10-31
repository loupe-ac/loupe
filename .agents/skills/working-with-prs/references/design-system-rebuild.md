# Hand a Design System rebuild to the owner

Read this file when `bin/agents/design-system-changed` printed a path and the
`loupe-design-rebuild` skill cannot write the artifact. A rebuild cannot write
when the `Artifact` tool is missing, as in a headless worker.

1. Write the line `Design system rebuild: pending` in the pull request body.
2. Call `inbox_search` with `Design system rebuild`. Stop here when a row with
   state `open` asks for the rebuild. One rebuild covers every pull request that
   is pending, so a second item only adds noise.
3. Otherwise call `inbox_ask` with one `todo` item titled
   `Rebuild the Design System artifact`. Set `blocking` to `false`, so the ask closes at once and
   does not end your turn. Link the card in `cardIds`.
   Suggest one `start-session` action with the `cardId` of that card and
   `recommended: true`. Give it a one-line `reason`.
   Set its `prompt` to: "Use the loupe-design-rebuild skill in this main
   checkout. First check that the branch is `main`, and run
   `git pull --ff-only`."
   The `loupe-inbox` skill gives the full parameters.
4. Leave the card and the pull request out of the title. The
   `Design system rebuild: pending` line on each pull request names the work.
