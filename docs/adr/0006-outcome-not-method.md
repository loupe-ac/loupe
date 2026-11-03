---
title: "0006: State the outcome that Loupe needs, and leave the method to the repository"
description: "Loupe text says what must be true for the product to work. The rules of each repository decide how an agent makes it true."
---

## Status

Accepted on 2026-11-02.

## Context

Loupe ships text to every project: the plugin skills, the design sessions, the output of `loupe init`, the starter profile and the setup prompts. An agent in another repository reads that text as an instruction. Loupe knows nothing about that repository, and each repository already has its own rules for how work gets done.

The rule that the plugin holds only what the product defines came from card 1329 (make the plugin product-only). ADR 0002 applies the same idea to one area, the environment of a stage skill. Neither record answers a more common question: what does Loupe text say when Loupe needs a result that a repository can reach in several ways?

On 2026-11-02, the product design session of card 1339 (make `loupe init` finish a working setup) showed the gap. The session asked the owner how `.loupe/lifecycle.md` gets to the remote, and offered a push and a pull request as answers. Loupe needs workers to read that file. How it gets there is a matter for the rules of that repository, and the agent that does the work can read those rules. The question gave the owner a decision that was never theirs to make, and a design built on the answer would write one repository's habit into the product.

The same shape appears wherever a method has several valid forms, such as how to run tests, how to install dependencies, how to review a change, where to record a decision, or how to name a branch.

## Decision

Loupe text states the outcome that Loupe needs. The rules of the repository decide how an agent reaches it. An agent asks nobody about a method that those rules cover, and a Loupe design specifies no such method.

The test for each sentence: does Loupe read this result or act on it? When it does, state the result. When the sentence only describes one way to reach a result, leave it out.

The rule in detail:

- Scope. The rule covers all Loupe text that an agent in another repository reads, and the designs that produce that text.
- State an outcome that somebody can check. Say what must be true, in terms of what Loupe reads or does. A vague outcome forces each agent to guess. A repository that uses another method must still be able to meet it. "Workers can read the profile" meets this. "The change is pushed" names a step, and the step can succeed while the outcome stays false.
- The repository owns the method. The agent reads the instruction files and the skills of the repository, and follows them.
- A repository with no rule. The agent uses its usual default, and tells the owner what it did. It does not stop to ask, and Loupe supplies no default.
- A real blocker goes to the owner. The agent asks when the rules of the repository contradict each other, when they contradict the outcome, or when only a person can act.
- The surface of the product stays named. Loupe text names its own tools, commands, files and profile sections. It also names a step when the product reads that step. A stage skill opens a pull request because the workflow reads the pull request, and it calls `loupe env refresh` because the bridge runs the command behind it.
- The profile holds values, as ADR 0002 says. A stage reads a value from the profile when it must run a repository's command. This rule does not add a profile section for each method, because the instruction files already hold them.

Apply this rule when you write or change Loupe text or design a Loupe feature. When you find an existing sentence that names a method, rewrite it as an outcome, or raise a card to do so.

## Rejected options

- Give a default method, and let the profile override it: this is the most helpful text for a repository that has no rules, and it costs little to write. But the default records the habits of this repository, and an agent in another repository may follow it over a rule that it did not read.
- Ask the owner each time: the answer is always correct, and no agent guesses. But the owner has often written the rule down already, each question costs them attention, and an unattended worker cannot ask.
- Extend ADR 0002: the two records share a root. But ADR 0002 decides who owns an environment, and it covers stage skills only. A wider rule in it would hide both decisions.
- Keep the rule in agent memory: this needs no record, and it already exists there. But only the sessions that load that memory see it, and it gives a reader no reasons or limits.

## Consequences

Better:

- Loupe fits a repository with any process, and a change to that process needs no change to Loupe.
- The owner gets fewer questions, and the questions that remain are about the product.
- Designs stay shorter, because they leave out steps that the product never reads.

Worse:

- Loupe cannot promise how a result arrives. Two repositories can take very different paths and different amounts of time to reach it.
- An outcome is harder to write than a step. A vague outcome is worse than a step, because each agent then fills the gap in its own way.
- In a repository with no rules, the agent's default can be a poor fit. The owner learns this only from the report afterwards.
- Stating an outcome does not make it true. A method that fails shows up later, when a stage cannot read what it needs. A stage already stops with a named cause in that case, and a discovery run can check the outcome before that.

Watch for a sentence that names a method and looks generic. "Run the tests" reads as harmless, and it still assumes that the repository has tests and a way to run them that the text can name. Also watch for the opposite fault: an outcome so abstract that no agent can tell when it is true.
