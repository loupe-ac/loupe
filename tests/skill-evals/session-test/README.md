# Design session test

This test runs a real Loupe design session with a third-party interview skill installed. It checks that Loupe keeps every output.

The third-party skill is `brainstorming` from `superpowers-extended-cc`. It writes a spec under `docs/superpowers/specs/`, commits it, and names `writing-plans` as the next skill. The rules of `plugins/loupe/skills/loupe-interviewing/references/delegation.md` must stop all three.

## Run it

```bash
node tests/skill-evals/session-test/run.mjs product   # /loupe:product-design
node tests/skill-evals/session-test/run.mjs tech      # /loupe:tech-design
```

Options: `--out DIR`, `--skill-plugin DIR`, `--model M`, `--max-cost USD` (per turn), `--max-turns N`. The script finds the skill plugin in `~/.claude/plugins/cache/superpowers-extended-cc-marketplace`, or reads `SKILL_PLUGIN_DIR`. A run costs about $3 and needs the `claude` CLI and Node. The exit code is 1 when a check fails.

## What it does

1. It copies `plugins/loupe` without `hooks/`, because the hooks call the `loupe` CLI of the host.
2. It makes a temporary git repository with one commit.
3. It starts `claude -p` with both plugins and `loupe-stub.mjs` as the `loupe` MCP server. The stub returns plausible JSON and logs every call to `loupe-calls.jsonl`.
4. It plays the owner with fixed replies, one `--resume` per reply. The first reply picks the `brainstorming` skill when the session asks which method leads. The mode is `bypassPermissions`, so a spec write would succeed and show.
5. It stops after `card_run_close`, or after the turn limit.

## Checks

1. Each `claude` turn exited 0 and ended with a result event that is not an error.
2. The session called `card_run_close`. A run that uses every turn without that call fails.
3. The `brainstorming` skill was loaded. Without it, the other checks prove nothing.
4. No file exists under `docs/superpowers/`.
5. The repository has no new commit.
6. No `writing-plans` skill load or read.
7. `document_create` was called, each call asked for a draft, and `document_publish` was not called.
8. One entry of the last written document version names the skill and its plugin. A product session writes that entry in `## Decisions log`, and a tech session writes it in `## Decided`. A title or another section does not count.

`tests/js/session_test_run.test.js` runs the script with a fake `claude`, and covers checks 1, 2 and 8 and the draft part of check 7.

## Output

The output folder holds `transcript.jsonl`, `loupe-calls.jsonl`, `result.json` and the temporary repository. The default folder is under the system temporary folder.

## Limits

The run has no AskUserQuestion tool, so the session asks in chat. It covers the chain, not the owner's real screen. The replies are fixed, so one run is one sample of a model that varies.
