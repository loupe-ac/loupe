# Feeding facts to the workflow engine

Read this before a module gives the workflow engine a fact, a condition or a listener that re-evaluates cards.

A module gives the engine its facts through `App\Module\Workflow\Contract`. Import nothing else from Workflow. `just arkitect` enforces this.

1. Write a `final readonly` facts class in your module. It holds what your conditions read about one card.
2. Implement `FactProvider` in your module. The `app.workflow_fact_provider` tag is autoconfigured. `build()` returns your facts class. `fingerprint()` returns the part whose change must retry a refused rule at once. `source()` returns the translation key of your module label. One facts class takes one provider.
3. Implement `Condition`. `reads()` returns `[YourFacts::class]`. `evaluate()` reads `$facts->get(YourFacts::class)`. Add the `workflow.waiting.<key>` and `workflow.waiting.not.<key>` strings to `translations/messages.en.xlf`. Add the key and its source to `ConditionCatalogueTest`.
4. Write a listener in your module for your own domain event. Inject the `CardEvaluations` port. Call `isOn()` before your repository read, and return when it is false. Then call `forCards()` with the ids of the cards whose facts changed.

## When a rule waits

A rule that reads a source the engine cannot read waits. The engine runs the other rules and writes no rule state for the waiting rule. A `not:` does not make the rule true.

1. `isOn()` answers false. The rule waits, and the engine logs nothing.
2. `build()` throws. The rule waits, and the engine logs one error per evaluation.
3. No provider gives the facts class. The rule waits, and the engine logs one error per evaluation.
4. A stored template copy names a condition that no longer exists. That rule waits, and the settings page still shows the template.
