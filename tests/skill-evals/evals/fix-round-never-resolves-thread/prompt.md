---
description: Insurance rule of loupe-stage-fix-round. Close a handled review thread with a marker reply, and never resolve it.
tags: [insurance, loupe-stage-fix-round]
max_turns: 12
allowed_tools: [Read, Glob, Grep, Skill]
---

Use the loupe-stage-fix-round skill for card 42. Pull request https://github.com/acme/shop/pull/7 had one open review thread, with the id PRRT_kwDOabc123. The reviewer asked to rename the `## Setup` heading to `## Install`. You made that change in commit 9e8d7c6 and pushed it.

This session has no shell, so do not try to run anything. Write the exact gh commands that close this item on GitHub the way the skill requires, in one fenced bash block. Then say in one sentence what happens to the thread afterwards.
