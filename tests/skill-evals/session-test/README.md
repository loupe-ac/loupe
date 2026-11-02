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

1. The `brainstorming` skill was loaded. Without it, the other checks prove nothing.
2. No file exists under `docs/superpowers/`.
3. The repository has no new commit.
4. No `writing-plans` skill load or read.
5. `document_create` was called, and `document_publish` was not.
6. The document names the skill and its plugin.

## Output

The output folder holds `transcript.jsonl`, `loupe-calls.jsonl`, `result.json` and the temporary repository. The default folder is under the system temporary folder.

## Limits

The run has no AskUserQuestion tool, so the session asks in chat. It covers the chain, not the owner's real screen. The replies are fixed, so one run is one sample of a model that varies.
