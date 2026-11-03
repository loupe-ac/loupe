# Codex unattended run notes

The stage skills read this file when they run in Codex, including an unattended `codex exec` worker. It holds only what differs in an unattended run.

At the `workspace` sandbox level, the bridge gives the worker network access and access to the main `.git` folder. The sandbox can refuse a command, and the command then fails. Report that failure as a block. Never route around the sandbox.

## Connect to the Loupe tools

Codex lists the MCP tools from its configuration directly, so the tools of the `loupe` server are in the tool list. Codex has no tool search. A first call can fail while the server still connects, so try the call again a few times. When every call fails, the connection failed.

## Run a long command

Use this pattern for a local command only. No stage waits for CI.

A call of the shell tool, `exec_command`, has a time limit. Start the long command detached. Write its output to a log file, and append an exit marker when it ends:

```bash
nohup sh -c '<command> > <log> 2>&1; echo "EXIT=$?" >> <log>' >/dev/null 2>&1 &
```

Then wait in the foreground with this loop. Keep each call below the time limit of the tool:

```bash
for i in $(seq 1 57); do grep -q '^EXIT=' <log> && break; sleep 10; done; grep '^EXIT=' <log> || echo still-running; tail -25 <log>
```

One loop waits 570 seconds at most. When it prints `still-running`, run it again. `EXIT=0` means the command passed. Never end the turn while the command runs.

## Read a linked prototype

Read a stored artifact with `artifact_get` and `artifact_read`, a file of the repository from disk, and a Loupe document with `document_get`. This harness has no tool for a `claude.ai/artifact/` link.

## Final reply

`codex exec` returns the final message as the structured result that `--output-schema` asks for, and the bridge writes it to a file with `-o`. Put the `STAGE RESULT:` line in that message, as the stage skill says. Keep the message below 4 KB.
