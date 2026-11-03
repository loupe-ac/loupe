# Claude Code unattended run notes

The stage skills read this file when they run in Claude Code, including an unattended `claude -p` worker. It holds only what differs in an unattended run.

## Connect to the Loupe tools

Search for the Loupe tools with ToolSearch. In `claude -p` the Loupe MCP server can still be connecting at the first search, so search again, up to six times. When every search fails, the connection failed.

## Run a long command

Use this pattern for a local command only. No stage waits for CI.

A Bash call ends after 600000 ms. Start the long command with the Bash tool's `run_in_background`. The tool then says that it will notify you when the command completes. In a headless run that notice never arrives, so always run the loop below. Write its output to a log file, and append an exit marker when it ends:

```bash
<command> > <log> 2>&1; echo "EXIT=$?" >> <log>
```

Then wait in the foreground with this loop, and a Bash timeout of 600000:

```bash
for i in $(seq 1 57); do grep -q '^EXIT=' <log> && break; sleep 10; done; grep '^EXIT=' <log> || echo still-running; tail -25 <log>
```

One loop waits 570 seconds at most. When it prints `still-running`, run it again. `EXIT=0` means the command passed.

## Read a linked prototype

Pick the tool by the kind of link:

| Link | Tool |
|---|---|
| A stored artifact | `artifact_get`, then `artifact_read` for each file. A headless worker uses these. |
| `claude.ai/artifact/<id>` | The `Artifact` tool. A headless `claude -p` worker has none. |
| A Design canvas | The `Artifact` tool and `ArtifactComments`, or the `mcp__claude-artifacts__*` tools of the `claude-artifacts` MCP in a CLI session. A headless `claude -p` worker has neither, so it reads the stored artifact. |
| A Loupe document | `document_get`. |
| A file of the repository | Read. |

A Claude Docs `read` denial says nothing about an artifact. Claude Docs is a different store, and it denies an id that names no doc as it denies a doc that is not shared. Never read it as "not shared".

## Final reply

`claude -p` prints only the final reply, and the bridge keeps the first 4 KB of it.
