---
title: "Skill tests: loupe-recommendations"
description: "The six cases that test the loupe-recommendations skill, and how to run them again."
---

These pages are not published on the documentation site. They hold the six cases that test the `loupe-recommendations` skill, so a later change to the skill can run them again.

## Run a case

1. Make a folder outside the checkout. Write the fixture files of the case into it, with the paths that the case page gives.
2. Cases 1 to 4 use this prompt. Cases 1 and 2 hold a `priorities.md`, and the prompt asks the agent to follow it when it exists.

   > You are the agent of a design stage. Read every file in the current folder (report.md first), and follow priorities.md when it exists. Pick one option for the tech design and write the recommendation the owner reads: the option you recommend, the reasons, and what the other option costs or leaves behind. At most 150 words. Do not edit any file.

3. Cases 5 and 6 use this prompt. `<checkout>` is the path of the checkout. These cases test the skill and the review checklist. They do not run the `loupe-stage-review` procedure, which needs a card and an open pull request.

   > You are the reviewer of an agent review. Read `<checkout>`/plugins/loupe/skills/loupe-stage-review/references/checklist.md, then context.md and change.diff in the current folder. Report your findings, each with a severity (blocking, important or minor) and a title, or say no findings. Then give a one-line verdict. At most 150 words. Do not edit any file.

4. To test with the skill, start the prompt with: "First load the skill loupe-recommendations with the Skill tool and follow it."
5. Run it with `printf "%s" "$PROMPT" | claude -p --plugin-dir <checkout>/plugins/loupe --permission-mode bypassPermissions` from the fixture folder. Run each case three times, because answers vary.

## Result of the first run (2026-10-19)

One run per case without the skill (baseline), then three runs per case with it. Every case passed in every run.

| Case | Baseline | With the skill |
|---|---|---|
| 1. Cheap patch hides a defect | pass | 3 of 3 |
| 2. Correct fix needs a migration and a release | pass | 3 of 3 |
| 3. Two correct options | pass | 3 of 3 |
| 4. Patch breaks a decision record | pass | 3 of 3 |
| 5. Reviewer reads a needed branch | pass | 3 of 3 |
| 6. Reviewer reads a patch | pass | 3 of 3 |

The baseline also passed, so these cases guard against a regression. They do not prove that the skill changed the answer. One difference shows: in cases 1, 2 and 4 every run with the skill calls the weaker option "a patch" and names what it leaves behind. No baseline run used the word. A harder fixture is the next step when a model fails a case without the skill.
