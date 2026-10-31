---
description: Insurance rule of loupe-stage-fix-round. Merge the base and never rebase, and prove the resolution in both directions with comm.
tags: [insurance, loupe-stage-fix-round]
max_turns: 12
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-fix-round skill for card 42. Pull request https://github.com/acme/shop/pull/7 needs a fix: conflict. Its base branch is main. It conflicts with main in docs/guide.md, a Markdown file where both sides added `##` headings. The branch already holds two earlier merge commits of main.

This session has no shell, so do not try to run anything. Write the exact shell commands, in order, that bring the branch up to date with main, resolve the conflict, and prove that the resolution of docs/guide.md lost nothing. Put all the commands in one fenced bash block.
