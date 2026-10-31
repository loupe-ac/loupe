---
name: loupe-recommendations
description: "Use when you recommend, choose or review a solution: a design decision, a fix, a plan, a review finding, or a patch against a fix."
---

# Recommending and reviewing a solution

This skill says how effort weighs against correctness. It holds the whole rule. Stage skills and instruction files point here and never copy it.

## Correct fix and patch

1. A fix is correct when it removes the cause of the defect.
2. A patch hides the symptom. The cause stays in place.
3. A fix is also correct only when it agrees with the decision records of the project. A change that breaks an accepted record is a patch, however well it works.

## When the rule applies

1. The rule applies when one option leaves a defect and another option removes it.
2. When every option is correct, the priorities of the project decide. Without priorities, the simpler option wins.
3. A defect is a demonstrated failure, a known gap, or a break of a decision record. A guess about a future problem is not a defect.

## Effort is a cost to report

1. More code, a new condition, a new branch, more files, a migration or a new release is a cost. Report it.
2. Effort alone never makes a patch the better option.
3. Never rank a correct fix lower because it needs a new condition, a new release or more moving parts.
4. A release step is context. It can change how the owner schedules the work. It does not change which option is correct.

## Name a patch

1. Call a patch a patch.
2. Name the defect that it leaves in place, and say who or what still hits it.
3. Do not call a patch "the pragmatic option" or "good enough" without that statement.

## An expensive correct fix

1. Recommend the correct fix, also when it is expensive.
2. Give the severity of the defect and the cost of the fix.
3. Say that the owner can choose the patch, and what the patch leaves behind.
4. Put a patch that you offer as a stopgap after the correct fix. Name the date or the event that ends it.
5. The owner sets the bar. State your recommendation as a recommendation.

## A real risk of the larger fix

Added code can add real defects. A measured or demonstrated risk of the larger fix is a correctness argument. State the risk, and say how you know it. The effort rule does not cover it. A risk that you only suspect is a cost to report, not a reason to pick the patch.

## In a review

1. Report a patch that leaves a known defect as a finding. Name the defect, and name the cause that the change leaves in place.
2. Do not report a branch, a condition or a file that a correct fix needs as needless complexity. Needless complexity is code that no requirement, defect or decision needs.
3. Test each branch of the diff with one question: which defect or requirement needs it? When one does, it is not needless.

## Decision records

1. Read the `Decision records` section of the repository profile at `.loupe/lifecycle.md`. It names where the project keeps its decision records and its priorities.
2. Read the records that touch the code of the choice. An accepted record is part of the definition of correct.
3. When the profile has no such section, skip the record check and say so in one line.
