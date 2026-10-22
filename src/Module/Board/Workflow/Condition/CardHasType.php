<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\ReadsCardType;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasType implements Condition, ReadsCardType
{
    #[\Override]
    public static function key(): string
    {
        return 'card.type';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('types', ParameterType::List, formerName: 'type')];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [CardTypeFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return \in_array($facts->get(CardTypeFacts::class)->type, ParameterValue::strings($params, 'types'), true);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_type' : 'workflow.waiting.card_type', ['%type%' => implode(' or ', ParameterValue::strings($params, 'types'))]);
    }

    #[\Override]
    public function cardType(array $params): array
    {
        return ParameterValue::strings($params, 'types');
    }
}
