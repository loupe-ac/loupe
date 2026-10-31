# Skill evals

This suite tests the skills of the Loupe plugin (`plugins/loupe`) offline, with `claude plugin eval`. Each case gives Claude a prompt with only the plugin loaded, and graders check what Claude did. The `loupe` MCP tools are mocks, so a run never reaches a Loupe instance.

## Run it

```bash
just skill-eval                       # every case, 3 runs each
just skill-eval --case board-*        # the cases whose name matches a glob
just skill-eval --tag insurance       # the cases with one tag
just skill-eval --runs 1              # a quick, noisy pass
```

The recipe runs on the host, because it calls `claude`. It copies `plugins/loupe` into a temporary folder and adds these to the copy:

1. A `.mcp.json` that declares a server named `loupe`. A mock attaches only to a server that the plugin declares, and the real plugin declares none, because `loupe init` adds the server at user level. The run never starts this server, so its URL can point nowhere.
2. The `evals/` folder of this suite.

The copy leaves out `hooks/`. Its SessionEnd hook calls the real `loupe` CLI on the host, outside the sandbox of the run.

The recipe passes `--ablation none`, `--model claude-opus-5-5` (the model of the bridge workers), `--max-cost-usd 15` and `--no-publish`. Flags you add come last and win. A full run costs about $4.

Results go to `tests/skill-evals/results/<time>/`, which git ignores. Each holds `aggregate-result.json` and `report.html`. The command exits 1 when any case scores below 1.0, and the `wrong-passage` cases do that until their passages are fixed.

## Why there is no baseline arm

`claude plugin eval` can repeat each case with no plugin loaded. That arm has no `loupe` server, so no mock exists and every grader on a `loupe` tool fails there. The delta it reports is then meaningless. This suite compares one revision of the plugin with another instead.

## Compare two revisions

Set `SKILL_EVAL_REF` to take the plugin from a git ref. The cases always come from the working tree, so both runs use the same suite.

```bash
SKILL_EVAL_REF=origin/main just skill-eval
just skill-eval
```

Then put the two scores of each case side by side:

```bash
score() { jq -r '.cases[] | "\(.name)\t\(.aggregates.score)"' "$1/aggregate-result.json" | sort; }
join -t $'\t' <(score tests/skill-evals/results/<run A>) <(score tests/skill-evals/results/<run B>)
```

Each case runs 3 times, so a score moves in steps of a third. A drop of one step can be noise. Run the case again before you trust it.

## Add a case

1. Make a folder under `evals/`, named for the rule it tests.
2. Write `prompt.md`. The frontmatter holds `description`, `tags`, `max_turns` and `allowed_tools`. The body is the prompt.
3. Put one grader per file in `graders/`.
4. Add a mock to `evals/mocks/loupe/<tool>.md` for each `loupe` tool that the case needs. A mock in `<case>/mocks/loupe/` overrides the suite mock for that case alone.
5. Run the case with `just skill-eval --case <name> --runs 1`.
6. Break the rule in your local copy of the skill, and run the case again. The grader must go red. Then restore the skill.

The report and `aggregate-result.json` hold the grader verdicts, not the reply of Claude. To read the reply, add `--keep-temp`, and open `trace.jsonl` in the folder that the run prints. Run `chmod 700` on that folder and on its `sealed/` folder first, because the run locks them.

Keep a case at the point of the decision. A run has no shell, so a case cannot push or run git. Tell Claude what already happened, and ask for the next step, the final `STAGE RESULT` line, or the exact commands.

Prefer graders that cost nothing: `tool_used` with `input_match`, `tool_order`, and `regex`. A `tool_used` grader names a `loupe` tool as `mcp__plugin_loupe_loupe__<tool>`. Add a `tool_used` grader on `Skill`, so a run where the skill did not fire is easy to see.

A grader that says a tool was never called (`min: 0`, `max: 0`) needs a mock for that tool. A tool with no mock is absent from the run, so the grader passes for nothing.

The mocks have no `_tools.json`, so each mocked tool carries a placeholder description. That is deliberate. The skill is then the only source of what the tool does, which is what the suite tests. With the real descriptions, a stale passage in a skill can hide behind a correct tool description.

## Tags

- `insurance`: a rule against a rare and expensive failure. These cases must pass.
- `wrong-passage`: a passage that the audit found wrong. The case passes once the passage is fixed, and fails until then.
- The skill name, such as `loupe-board`, for a run of one skill with `--tag`.
