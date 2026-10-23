<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Expression\Expression;

final readonly class Rule
{
    /** The first action of the rule. The engine reads the bookkeeping of a rule from it, and a rule with several actions holds only actions that need none. */
    public ActionCall $lead;

    /**
     * @param ?string                    $slot a slot key, '@backlog', '@terminal', or null for a rule that applies in every column
     * @param non-empty-list<ActionCall> $then the actions of the rule, which run in order
     */
    public function __construct(
        public string $id,
        public ?string $slot,
        public Expression $when,
        public array $then,
        public RuleOrigin $origin = RuleOrigin::Template,
    ) {
        $this->lead = $then[0];
    }

    /** The rule that runs one action of this rule. The engine hands each action of a list the context of its own call. */
    public function forCall(ActionCall $call): self
    {
        return [$call] === $this->then ? $this : new self($this->id, $this->slot, $this->when, [$call], $this->origin);
    }

    /** Whether an action of the rule moves the card, so the rules after it wait for the next pass. */
    public function endsPass(): bool
    {
        return array_any($this->then, static fn (ActionCall $call): bool => $call->traits->endsPass);
    }

    /**
     * What the action of the rule reads when it runs on the card. An ask hands over the labels of its options.
     *
     * @param array<string, ColumnRef> $columns the column of each slot parameter that has one
     * @param ?string                  $prompt  the text of the prompt that the rule names
     */
    public function context(CardSnapshot $card, Facts $facts, int $fires, array $columns = [], ?string $prompt = null): ActionContext
    {
        $params = [...$this->lead->params, ...$this->lead->lists];
        if ([] !== $this->lead->options) {
            $params['options'] = array_map(static fn (AskOption $option): string => $option->label, $this->lead->options);
        }

        return new ActionContext($card, $this->id, RuleOrigin::App === $this->origin, $params, $facts, $fires, $columns, $prompt);
    }
}
