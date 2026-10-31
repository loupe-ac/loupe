---
title: "Questions people ask about Loupe"
description: "Straight answers to the questions people ask about Loupe: quality, token use, Anthropic's terms, self-hosting, models, data and cost. An answer with no evidence yet says so."
updatedOn: 2026-10-27
---

Each answer cites its sources. A figure from the Loupe analytics or the git history cites a dated note in [How we counted](#how-we-counted). An answer that is not complete carries an **Open.** paragraph. That paragraph says what work would finish it.

## Quality and cost

### How do you know the agents' output is good? Do you measure it?

A separate agent reviews each design and each pull request, and a fix round answers its findings. A workflow allows up to 10 fix rounds. [1] A person reviews a pull request only when the card has the **Requires human review** switch on. Otherwise the workflow can post the approval check by itself. [1]

Experiments compare variants of a workflow by merge rate, stop rate, fix rounds and cost per merged card. [2] No benchmark score exists for Loupe. This page gives none.

### How many tokens does Loupe use, compared with plain Claude Code or a competitor?

The Loupe analytics keep four token counts apart. [3] From September 2026 to 2026-10-27 the recorded worker runs that built Loupe used 352,786,202 input tokens, 92,942,620 output tokens, 20,901,741,739 cache read tokens and 610,792,444 cache write tokens. That is about 21.96 billion tokens in all. [14] Cache reads are about 95% of that total. Each model request of a run reads the conversation so far from the prompt cache again, so the same text counts once for each request. Output tokens are the small part.

The same 4,196 runs cost $12,048.54 at API list price. [14] That scope is the closed worker runs with a recorded value, since the analytics began. It is not the full cost of building Loupe. 716 closed runs have no recorded value, and runs before September 2026 are missing. The owner pays a subscription, not this amount. Your own analytics show the cost and the tokens of each run and each card. [3]

**Open.** No comparison exists. Loupe has no figure for plain Claude Code or a competitor on the same work. The work that answers it: run the same small card twice, once through Loupe and once by hand in plain Claude Code, and compare all four token counts from both runs.

### What does the hosted version cost, and can I self-host?

You can self-host. Loupe is open source under the AGPL-3.0-or-later licence. [9] The install guide covers four install paths. [10] A signed-in user sees the hosted plan and its price on the billing tab. [8]

**Open.** The repository holds no hosted price, so this page gives none. The work that answers it: the owner states the public price of the hosted plan, and this answer then quotes it.

### An agent goes wrong at 2am. Who stops it, and what does it cost?

A person can stop a run, cancel its work, or make the card unmanaged, from the run page or the MCP tools. [15] A bridge can pause. A run that fails again and again ends in a card pause, and fix rounds have a work limit. [1] [15]

No cost cap and no token cap exist. A run that goes wrong costs what it uses until a person or a limit stops it. The analytics show the cost of each run. [3]

## Terms and models

### May I run scheduled agents on my personal Claude login?

Loupe's bridge runs the published `claude` command on your own machine, with your own login or API key. Loupe never sees the credential. [11]

Anthropic's page on Claude Code legal and compliance says three things that matter here. [4] It permits "an end user signing in to the unmodified Claude Code binary with their own Claude subscription". It forbids a third party to "route requests through Free, Pro, or Max plan credentials on behalf of their users". It also says that "advertised usage limits for Pro and Max plans assume ordinary, individual usage of Claude Code and the Agent SDK".

This page gives no ruling on how those words apply to many unattended runs. Read the page, and decide for your own account. [4]

### Does it work with models other than Claude, or with local models?

The bridge runs Claude Code and Codex. Other providers work through a Codex profile, such as OpenRouter. [11] The account check accepts a Codex profile with a local provider such as `ollama`, which needs no login. [11]

**Open.** Nobody has run a full stage on a local model. The work that answers it: run one small card through a Codex profile on a local model, and record whether each stage finishes.

## Your code and data

### Where does my code go, and what does Loupe see?

Your code goes to three places.

1. The model provider. The agent runs on your machine, but it sends prompts, the file content it reads and its outputs to the provider of the account. A Claude Code account sends them to Anthropic, under the retention rules of your Anthropic plan. [12] A Codex account sends them to the provider that its profile names, such as OpenAI or OpenRouter. [11] Loupe does not choose or proxy that traffic. With an OpenRouter management key, Loupe reads the billed cost of each Codex run from OpenRouter. It sends OpenRouter only the key, the session of the run and its time range, and never a prompt, code or card content.
2. The repository host. A worker pushes its branch and opens the pull request on the forge of the project, such as GitHub. The Loupe GitHub App holds Contents read and write on each repository that installs it. [13]
3. The Loupe server. For each worker run it receives the run state and up to 4,000 characters of worker output. For each tool call it receives the tool name, kind, timing and command signatures. Setting `collect: false` in `rules.yaml` sends no tool calls. The full input text of a tool call goes to the server only when the project setting **Collect the full text of each tool call** is on, and it is off by default. [11] A command run, such as a worktree set-up command, sends its live output, up to 1 MiB per run. [14] [15] Documents, cards and review comments that an agent writes can quote code, and the server stores them.

Loupe cannot keep code away from the model provider, because the agent needs the code to work.

## How Loupe compares

### How is Loupe different from other tools?

Loupe has seven comparison pages, each with dated sources. [5] Start at the [list of comparisons](/compare).

### Why Loupe when I already run T3 Code on my own subscription?

T3 Code is a control surface for agents on your machine. Loupe is a board and workflow that decides which work agents do, with document review and an inbox. The two solve different problems. Read the [T3 Code comparison](/compare/t3-code) for the side-by-side table. [5]

### Is there a mobile app?

No native app exists. The web app installs as a progressive web app, and web push works on iOS and iPadOS 16.4 or later from the Home Screen. [6]

### Does it run on Windows?

The `loupe` CLI runs on macOS and Linux, with no Windows build. [16]

### Can my team use it?

Not yet. Each project has one owner, and Loupe has no teams. [17]

### Is it open source?

Yes. The licence is AGPL-3.0-or-later, and the code is on GitHub. [7] [9]

## Built with Loupe

### Was Loupe built with Loupe? How much did a person write?

Loupe builds a large part of itself. The evidence has two parts, and they are not the same thing.

The Loupe-run work: the analytics of the Loupe project record 4,912 closed worker runs from September 2026 to 2026-10-27, and each run belongs to a card of the Loupe board. [14] 105 of 2,054 commits on `main` carry the `Loupe-Fix-Round` trailer, which a fix-round worker writes, from 2026-10-11 to 2026-10-27. [14]

The agent co-authorship: 1,486 of the same 2,054 commits carry a `Co-Authored-By: Claude` trailer. [14] That trailer says Claude co-wrote the commit. It does not say whether a Loupe worker or an interactive session ran Claude. It also does not measure the lines that a person wrote.

**Open.** The history of `main` cannot give the share that Loupe workers produced, or the share that a person wrote. A worker run records the head commit of its request, not the commits that it pushed. A commit with no trailer can hold agent output, and a commit with the trailer can hold lines that a person typed. So this page gives no percentage. The work for future commits: the bridge records the commits that each worker run pushes, and the share of commits that Loupe workers pushed is then countable from a given date. No tool records which lines a person typed inside a session, so the human share has no measure.

## How we counted

Each entry gives the date it was read, the scope, the query, and the result. The analytics of the Loupe project are private, so you cannot run these queries on it. A Loupe user can run the same queries on their own project. Anyone with a clone of the repository can run the git commands.

1. **C1: Agent cost and token use.** Read on 2026-10-27. Scope: the closed worker runs of the Loupe project on its own Loupe instance, from the first recorded run in September 2026. A run with no recorded value counts in no total. The analytics start at the upgrade that added them, so earlier runs are missing. Query: `metric_query` with `unit: run`, `statistic: sum`, `range: all`, `bucket: month`, once for each of `cost`, `input-tokens`, `output-tokens`, `cache-read-tokens` and `cache-write-tokens`. Each query counts the same 4,196 runs. Totals: $12,048.54, input 352,786,202, output 92,942,620, cache read 20,901,741,739 and cache write 610,792,444 tokens. The four token counts add up to 21,958,263,005. That sum counts cached text once for each request that reads it, so it is not the size of the distinct text. September 2026: 307 runs, $2,720.55, input 73,220,795, output 19,765,729, cache read 3,971,489,730 and cache write 113,085,979. October 2026 to the 27th: 3,889 runs, $9,327.99, input 279,565,407, output 73,176,891, cache read 16,930,252,009 and cache write 497,706,465. The dollar figure is the list price that `claude` reports, which a subscription does not pay.
2. **C2: Closed worker runs.** Read on 2026-10-27. Same scope and queries as C1, such as `metric_query` with `metric: cost`. The queries report 4,912 closed runs in total, of which 716 have no recorded value.
3. **C3: Commit counts.** Read on 2026-10-27. Scope: `main` of the public repository. Commands: `git rev-list --count origin/main` gives 2,054. `git rev-list --count -i --grep='Loupe-Fix-Round' origin/main` gives 105. `git rev-list --count -i --grep='Co-Authored-By: Claude' origin/main` gives 1,486.

## Sources

1. [Loupe documentation: workflows](https://loupe.ac/docs/using/workflows/)
2. [Loupe documentation: experiments](https://loupe.ac/docs/using/experiments/)
3. [Loupe documentation: analytics](https://loupe.ac/docs/using/analytics/)
4. [Anthropic: Claude Code legal and compliance](https://code.claude.com/docs/en/legal-and-compliance), read on 2026-10-27
5. [Loupe comparison pages](/compare)
6. [Loupe documentation: notifications](https://loupe.ac/docs/using/notifications/)
7. [Loupe repository and README](https://github.com/loupe-ac/loupe)
8. [Loupe documentation: billing](https://loupe.ac/docs/using/billing/)
9. [Loupe licence](https://github.com/loupe-ac/loupe/blob/main/LICENSE)
10. [Loupe documentation: install](https://loupe.ac/docs/operating/install/)
11. [Loupe documentation: the CLI bridge](https://loupe.ac/docs/extending/cli-bridge/)
12. [Anthropic: Claude Code data usage](https://code.claude.com/docs/en/data-usage), read on 2026-10-27
13. [Loupe documentation: forge webhooks and App permissions](https://loupe.ac/docs/extending/forge-webhooks/)
14. [How we counted](#how-we-counted), and the [worker run reference](https://loupe.ac/docs/reference/worker-runs/)
15. [Loupe documentation: worker runs](https://loupe.ac/docs/using/worker-runs/)
16. [Loupe documentation: installing the CLI](https://loupe.ac/docs/getting-started/cli/)
17. [Loupe documentation: projects](https://loupe.ac/docs/using/projects/)
