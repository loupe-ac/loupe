# Skill evals

This suite tests the skills of the Loupe plugins (`plugins/loupe` and `plugins/loupe-lifecycle`) offline, with `claude plugin eval`. Each case gives Claude a prompt with only the plugin loaded, and graders check what Claude did. The `loupe` MCP tools are mocks, so a run never reaches a Loupe instance.

## Run it

```bash
just skill-eval                       # every case, 3 runs each
just skill-eval --case board-*        # the cases whose name matches a glob
just skill-eval --tag insurance       # the cases with one tag
just skill-eval --runs 1              # a quick, noisy pass
```

The recipe runs on the host, because it calls `claude`. It copies `plugins/loupe` into a temporary folder, adds the skills of `plugins/loupe-lifecycle`, and adds these to the copy:

1. A `.mcp.json` that declares a server named `loupe`. A mock attaches only to a server that the plugin declares, and the real plugin declares none, because `loupe init` adds the server at user level. The run never starts this server, so its URL can point nowhere.
2. The `evals/` folder of this suite.

The copy leaves out `hooks/`. Its SessionEnd hook calls the real `loupe` CLI on the host, outside the sandbox of the run.

The recipe passes `--ablation none`, `--model claude-opus-5-5` (the model of the bridge workers), `--max-cost-usd 15`, `--no-publish`, `--scaffold` and a short `--allow-tools` list. The last two serve the foreign repository cases below. Flags you add come last and win. A full run costs about $4.

Results go to `tests/skill-evals/results/<time>/`, which git ignores. Each holds `aggregate-result.json` and `report.html`. The command exits 1 when any case scores below 1.0.

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

A run scores the fraction of its graders that passed, and a case score is the mean of its 3 runs. One grader that fails in one run therefore moves the case score by a small step, such as 0.07 for a case with 5 graders. A small drop can be noise. Run the case again before you trust it.

## Add a case

1. Make a folder under `evals/`, named for the rule it tests.
2. Write `prompt.md`. The frontmatter holds `description`, `tags`, `max_turns` and `allowed_tools`. The body is the prompt.
3. Put one grader per file in `graders/`.
4. Add a mock to `evals/mocks/loupe/<tool>.md` for each `loupe` tool that the case needs. A mock in `<case>/mocks/loupe/` overrides the suite mock for that case alone.
5. Run the case with `just skill-eval --case <name> --runs 1`.
6. Break the rule in your local copy of the skill, and run the case again. The grader must go red. Then restore the skill.

The report and `aggregate-result.json` hold the grader verdicts, not the reply of Claude. To read the reply, add `--keep-temp`, and open `trace.jsonl` in the folder that the run prints. Run `chmod 700` on that folder and on its `sealed/` folder first, because the run locks them.

Keep a case at the point of the decision. A run has no shell unless the case names `Bash` in `allowed_tools`, and only a foreign repository case does that (see the next section). The other cases cannot push or run git. Tell Claude what already happened, and ask for the next step, the final `STAGE RESULT` line, or the exact commands.

Prefer graders that cost nothing: `tool_used` with `input_match`, `tool_order`, and `regex`. A `tool_used` grader names a `loupe` tool as `mcp__plugin_loupe_loupe__<tool>`. Add a `tool_used` grader on `Skill`, so a run where the skill did not fire is easy to see.

A grader that says a tool was never called (`min: 0`, `max: 0`) needs a mock for that tool. A tool with no mock is absent from the run, so the grader passes for nothing.

## Foreign repository cases

The cases named `foreign-*` prove the skills on a repository that is not this one. Each runs in `tally`, a small Python library. It has no `just`, no Codex, no changelog and no epic preview, and its profile `.loupe/lifecycle.md` has no `Changelog` section. A case fails when a skill stops, asks for, or assumes something that only the Loupe repository has.

| Case | Skill | Decision point |
|---|---|---|
| `foreign-implementation-no-changelog` | `loupe-stage-implementation` | The run reaches the push and ends `waiting`. It never ends `blocked`, writes no changelog entry and runs no `just` command. |
| `foreign-merge-epic-preview-none` | `loupe-stage-merge` | The profile says `Epic preview: none`, so an epic preview request ends `STAGE RESULT: unchanged`. |
| `foreign-merge-epic-preview-head` | `loupe-stage-merge` | The worker folder is a plain folder. The refresh needs `HEAD` to equal `origin/<epic branch>` and nothing else. |
| `foreign-review-python` | `loupe-stage-review` | The review reads the change with git objects and never checks out the branch. It submits a finding on a division by zero at the full head commit. |
| `foreign-design-review-tech` | `loupe-stage-design-review` | The review reads the code that the tech design cites, and finds a false claim about it. It changes no document. |
| `foreign-fix-round-conflict` | `loupe-stage-fix-round` | A conflict with `main` is resolved with a merge, never a rebase. The gate comes from the profile, and the round ends at the push. |
| `foreign-repair-starter-profile` | `loupe-stage-repair` | The starter `Repair` section holds no action for a dirty folder, so the run records a `Repair:` paragraph and ends `blocked`. |
| `foreign-product-design-stage` | `loupe-stage-product-design` | One review round ends `revised`. The revision names no documentation or landing page check. |
| `foreign-tech-design-stage` | `loupe-stage-tech-design` | A profile with no tech design instructions and no deploy items still gets a draft design. |
| `foreign-discovery-no-visual-check` | `loupe-discovery` | A profile with no `Visual check` section gets no finding about a browser tool. |

Each case has a `case.yaml` that names a `scaffold_script`. The script sources `foreign-lib/repo.sh`, which builds the repository in the folder of the run. The remote is a bare repository in `.remote`, so fetch, merge and push work with no network. The Loupe tools are mocks. The forge is not: `gh` is not granted, so the prompt of a case lists the answers that `gh` would give.

The recipe passes `--scaffold`, which runs the script of each case that has one. It also passes `--allow-tools` for `git`, `python3`, `ls`, `cat`, `grep`, `test`, `mkdir`, `sleep`, `Write` and `Edit`. A case gets these only when its `allowed_tools` names `Bash`, `Write` or `Edit`, and no case outside the `foreign-*` family does.

On macOS the plain `git` command can be a shim that fails in the sandbox of a run, because it cannot write its cache. The scaffold makes `./.bin/git`, a wrapper for the real binary, and each prompt tells the run to use it. A grader that looks for a git command matches `git push`, which `./.bin/git push` contains.

A run costs between $0.25 and $0.70. The ten cases cost about $5 for one run each.

A foreign case does not cover these:

1. A stop in a real `gh` command. The forge answers are text in the prompt.
2. A Codex worker. The evals run Claude only.
3. The real session test of the design sessions.

Run the cases against an older revision to see which of them catch a fix:

```bash
SKILL_EVAL_REF=origin/main just skill-eval --case 'foreign-*' --runs 1
```

## Real tool descriptions

`evals/mocks/loupe/_tools.json` holds the `tools/list` answer of a real Loupe instance: the name, description and input schema of each tool. Each mocked tool carries them, so a run sees what a real session sees. A cut of skill text that restates a tool description can then pass. The schemas also catch a mock `expect:` that names a wrong field.

Regenerate the file with `just skill-eval-tools`. It runs `loupe mcp` against the instance that `loupe login` signed in to, and sends `initialize` and `tools/list` only. Run it again in these cases:

1. A pull request changes a tool description, a parameter or the tool list, and you want the suite to see it.
2. You compare a revision of the skills against a cut that relies on a tool description.
3. Before a baseline run that other runs will compare against.

Commit the new file with the change it belongs to. The file reflects the deployed instance, so a tool change that is not deployed yet needs the instance of your worktree: pass `--config` or `--project` through to `loupe mcp`.

## Tags

- `insurance`: a rule against a rare and expensive failure. These cases must pass.
- `wrong-passage`: a passage that the audit found wrong. The real tool description is right, so the case passes today. It fails when a change leaves the agent with the wrong rule.
- `weak`: a case with a grader that stayed green when its rule was broken in a local copy of the skill. The `description` of the case names those graders. A pass of such a grader is no proof that the skill holds its rule. A shell grant and a scaffolded repository would let a `tool_order` grader check the real commands, at the cost of a sandboxed Bash run per case.
- The skill name, such as `loupe-board`, for a run of one skill with `--tag`.
