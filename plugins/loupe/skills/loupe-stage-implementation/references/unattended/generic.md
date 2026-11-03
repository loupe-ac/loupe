# Generic unattended run notes

Use these notes when the current harness has no dedicated notes. Map each operation to the equivalent capability in the harness.

## Connect to the Loupe tools

Discover or load the Loupe MCP tools. Retry briefly when the server is still connecting. The connection failed when the tools remain unavailable.

## Run a long command

Use the harness's background process support and poll it in the foreground at least once a minute. Never end the turn while it runs. Preserve the complete log and the exit status. Stop when the command fails.

## Read a linked prototype

Read a stored artifact with `artifact_get` and `artifact_read`, a file of the repository from disk, and a Loupe document with `document_get`. This harness has no tool for a `claude.ai/artifact/` link.

## Final reply

Keep the final reply below 4 KB so every bridge can retain it.
