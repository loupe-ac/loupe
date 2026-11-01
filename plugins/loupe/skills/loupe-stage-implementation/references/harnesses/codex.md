# Codex adapter

The stage skills read this file when they run in Codex, including an unattended `codex exec` worker. It maps each harness step to the tools of Codex.

At the `workspace` sandbox level, the bridge gives the worker network access and access to the main `.git` folder. The sandbox can refuse a command, and the command then fails. Report that failure as a block. Never route around the sandbox.

## Connect to the Loupe tools

Codex lists the MCP tools from its configuration directly, so the tools of the `loupe` server are in the tool list. Codex has no tool search. A first call can fail while the server still connects, so try the call again a few times. When every call fails, the connection failed.

## Load an instruction

Codex finds skills in `.agents/skills` in each folder from the working directory up to the repository root, and in `$HOME/.agents/skills`. It follows symbolic links. Load a named instruction, such as `loupe-documents`, before you act. Read its whole `SKILL.md` with a shell command. Read each file that the instruction names in the same way.

## Ask nothing

Nobody answers a question in `codex exec`. Never ask a question in the chat and wait for an answer.

## Change a file

Change a file with `apply_patch`. Change files in the worker folder only.

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

## Dispatch a sub-agent

Dispatch a sub-agent with `spawn_agent`. Its arguments include `task_name` and `message`. Wait for it with `wait_agent`.

Codex has no named agent such as `senior-dev`. Start the message with the full text of the `senior-dev` instruction. Read it from `.agents/agents/senior-dev.md` in the repository when that file exists. Otherwise, write its essentials:

- Rank correctness first, then simplicity, then performance, then shipping speed.
- Read the instruction files of the repository before you change a file.
- Add nothing the task does not ask for. Load `loupe-recommendations` before you choose between a patch and a fix.
- Write a failing test first, then the code that makes it pass.
- Report a blocker and stop.
- End with the status, the changed files, the tests and their result, and any concern.

Then put the rules the stage skill names into the message, because the sub-agent inherits no loaded instruction. A sub-agent reads a prototype page with `artifact_read`. When it cannot call the `loupe` tools, put the text of its pages into its prompt. Wait for every sub-agent before the turn ends.

## Write the build notes

Write the build notes in plain Markdown, and follow the `loupe-documents` instruction. Submit them to Loupe. Save no build notes file. Nobody approves the build notes, so start the work as soon as it is linked.

## Run the build notes task by task

For each task, dispatch one implementer sub-agent, then one reviewer sub-agent. Run the tasks in order. Fix the findings of the review before the next task starts. Never merge locally into the base branch, and never remove the worker folder.

## Final reply

`codex exec` returns the final message as the structured result that `--output-schema` asks for, and the bridge writes it to a file with `-o`. Put the `STAGE RESULT:` line in that message, as the stage skill says. Keep the message below 4 KB.
