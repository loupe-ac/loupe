<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\ChildMark;
use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** An open child of the card stores a marker of the `kind`, so an epic can show what happens under it. */
final readonly class CardChildMarked implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.children.marked';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('kind', ParameterType::String, choices: array_map(static fn (MarkerKind $kind): string => $kind->value, MarkerKind::states()))];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [ChildrenFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== self::mark($facts, $params);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_children_marked' : 'workflow.waiting.card_children_marked', ['%kind%' => ParameterValue::string($params, 'kind')]);
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $mark = self::mark($facts, $params);

        return null === $mark ? null : new Evidence($mark->since, ['%number%' => $mark->number, '%title%' => $mark->title]);
    }

    /** @param array<string, mixed> $params */
    private static function mark(Facts $facts, array $params): ?ChildMark
    {
        return $facts->get(ChildrenFacts::class)->childMarks[ParameterValue::string($params, 'kind')] ?? null;
    }
}
