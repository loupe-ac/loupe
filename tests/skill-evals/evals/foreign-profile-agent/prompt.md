---
description: Rule of loupe-profile on a repository that is not Loupe. The small Python repository has no .loupe/lifecycle.md. The run reads the repository and workflow_get, and writes the profile with the gate command that CONTRIBUTING.md names. It writes no guess marker, and every required section is there.
tags: [foreign, loupe-profile]
max_turns: 40
allowed_tools: [Read, Glob, Grep, Skill, Bash, Write, Edit]
---

Use the loupe-profile skill. The Loupe project is p1. `loupe init` started you in the current folder, a small Python repository.

In this session the plain `git` command fails, because it is a shim. Run every git command as `./.bin/git`, from the current folder.

The owner is not here to answer. Treat the grouped view as approved. Answer every question about a value that the repository does not show with "leave it unknown". Write the file, then reply with the report.
