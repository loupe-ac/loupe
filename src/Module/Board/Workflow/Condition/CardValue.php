<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardValue implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.value';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('key', ParameterType::ValueKey),
            new Parameter('equals', ParameterType::Bool, required: false),
        ];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [CardValueFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $values = $facts->get(CardValueFacts::class)->values;
        $key = ParameterValue::string($params, 'key');
        if (!\array_key_exists($key, $values)) {
            return false;
        }
        $equals = ParameterValue::optionalBool($params, 'equals');

        return null === $equals || $values[$key] === $equals;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $key = ParameterValue::string($params, 'key');
        $equals = ParameterValue::optionalBool($params, 'equals');
        $base = $negated ? 'workflow.waiting.not.card_value' : 'workflow.waiting.card_value';

        return null === $equals
            ? new TranslatableMessage($base.'_set', ['%key%' => $key])
            : new TranslatableMessage($base, ['%key%' => $key, '%value%' => $equals ? 'true' : 'false']);
    }
}
