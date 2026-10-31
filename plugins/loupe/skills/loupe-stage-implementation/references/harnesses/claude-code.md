# Claude Code adapter

The stage skills read this file when they run in Claude Code, including an unattended `claude -p` worker. It maps each harness step to the tools of Claude Code.

## Connect to the Loupe tools

Search for the Loupe tools with ToolSearch. In `claude -p` the Loupe MCP server can still be connecting at the first search, so search again, up to six times. When every search fails, the connection failed.

## Load an instruction

Load a named instruction, such as `loupe-board`, with the Skill tool. Read a file of instructions with the Read tool.

## Ask nothing

Never call `AskUserQuestion`. Nobody answers it in an unattended run.

## Change a file

Change a file with the Edit and Write tools. Use no editor tool that writes outside the worker folder.

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

## Dispatch a sub-agent

Dispatch a sub-agent with the Agent tool. When the repository defines a `senior-dev` agent in `.claude/agents/senior-dev.md`, set `subagent_type` to `senior-dev`. Otherwise set it to `general-purpose`, and start the prompt with these essentials:

- Rank correctness first, then simplicity, then performance, then shipping speed.
- Read the instruction files of the repository before you change a file.
- Add nothing the task does not ask for. Load `loupe-recommendations` before you choose between a patch and a fix.
- Write a failing test first, then the code that makes it pass.
- Report a blocker and stop.
- End with the status, the changed files, the tests and their result, and any concern.

Put the rules the stage skill names into its prompt, because it inherits no loaded instruction. The sub-agent has every tool, so it reads a prototype page with `artifact_read` itself.

## Write the build notes

Write the build notes in plain Markdown, and follow the `loupe-documents` instruction. Submit them to Loupe. Save no build notes file. Nobody approves the build notes, so start the work as soon as it is linked.

## Run the build notes task by task

For each task, dispatch one implementer sub-agent, then one reviewer sub-agent. Run the tasks in order. Fix the findings of the review before the next task starts. Never merge locally into the base branch, and never remove the worker folder.

## Final reply

`claude -p` prints only the final reply, and the bridge keeps the first 4 KB of it.
