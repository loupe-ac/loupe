---
title: "Instruction audit"
description: "The monthly audit that keeps AGENTS.md and the skills short where it costs the most."
---

Every agent session reads `AGENTS.md`, and many sessions load one or more
skills. Each word in those files costs tokens on every load. The monthly audit
finds the files that cost the most and cuts what no longer earns its place.

## Rank the files by load cost

Run the script from any checkout of the repository:

```sh
bin/agents/instruction-load-cost                       # the last 30 days
bin/agents/instruction-load-cost --days 7              # a shorter window
bin/agents/instruction-load-cost --with loupe-board    # sessions that also load loupe-board
```

The script reads the Claude Code transcripts of this repository and of its
worktrees, under `~/.claude/projects` and `~/.claude-worker/projects`. It reads
them without changes and prints counts only. A skill load counts once per
`Skill` tool call. `AGENTS.md` counts once per top-level session. The load cost
of a file is its loads times its current word count.

The table also gives the share of the seven costliest files. Audit those files
first. A cut in a file at the bottom of the table saves almost nothing.

`--with <skill>` shows, per skill, how many sessions also load the named skill.
Use it to find a skill that another skill always pulls in. Such a pair can often
share one passage instead of two.

## Read the costliest files

Read each file in full. Mark two kinds of passages.

A wrong passage makes a claim that no longer matches the code. Fix it first,
because it costs tokens and also misleads the agent.

A cut candidate is correct, and it has one of these faults:

- A check, a hook or a test enforces the rule, and the failure message says what to do.
- Another file that the same sessions load says the same thing.
- The passage covers a case so narrow that few sessions meet it.
- The passage tells the history of a decision instead of the rule.
- The passage describes a process or a tool that changed or went away.
- The passage repeats the description of a tool that the agent already sees.

Keep an insurance rule, even when it looks redundant. An insurance rule
prevents a failure that is rare and expensive, such as data loss or a broken
production deploy. Its cost per load is small next to the cost of the failure.

## Verify each claim

Check every claim against the code before you propose a change. Read the
script, the hook or the check that the passage names. A second review by a
different model, such as a Codex review, finds claims that the first read
accepted too easily.

To test a cut in a skill under `plugins/loupe`, run `just skill-eval` before
and after the change. `tests/skill-evals/README.md` describes the cases and the
flags.

## Write the findings

Submit the findings as a Loupe document. Give the owner one decision per lever,
such as "cut the history passages of `working-with-prs`". Give each decision
the words it saves, the load cost it removes and the risk. Put the full list of
proposals, with the passage and the reason for each, in an appendix.

The owner decides which levers to pull. Each approved lever then becomes a
pull request that changes the files.
