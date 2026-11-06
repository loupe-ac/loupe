<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The card holds a value for the key, and it was written after the card arrived in its column and after the newest
 * approval of the approved documents with the tag. All times keep their microseconds, so an equal pair is a tie that cannot
 * show the write came later, and it fails. A card with no known arrival fails. With no approval, only the arrival counts.
 * With `byPerson`, a person must have written the value on the card page.
 */
final readonly class CardValueWrittenSinceApproval implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.value.written_since_approval';
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
            new Parameter('tag', ParameterType::String),
            new Parameter('byPerson', ParameterType::Bool, required: false),
        ];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [CardValueFacts::class, ColumnArrivalFacts::class, DocumentsFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $write = $facts->get(CardValueFacts::class)->writes[ParameterValue::string($params, 'key')] ?? null;
        $arrivedAt = $facts->get(ColumnArrivalFacts::class)->arrivedAt;
        if (null === $write || null === $arrivedAt || $write->at <= $arrivedAt) {
            return false;
        }
        if (true === ParameterValue::optionalBool($params, 'byPerson') && Actor::Human !== $write->writerKind) {
            return false;
        }

        $tag = ParameterValue::string($params, 'tag');
        $approvedAt = CardDocumentNewestApprovalByWorkflow::newestApprovalAt(array_values(array_filter(
            $facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => \in_array($tag, $document->tags, true) && $facts->readsDocument($document->id),
        )));

        return null === $approvedAt || $write->at > $approvedAt;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $byPerson = true === ParameterValue::optionalBool($params, 'byPerson');
        $base = ($negated ? 'workflow.waiting.not.' : 'workflow.waiting.').'card_value_written_since_approval';

        return new TranslatableMessage($byPerson ? $base.'_by_person' : $base, [
            '%key%' => ParameterValue::string($params, 'key'),
            '%tag%' => ParameterValue::string($params, 'tag'),
        ]);
    }
}
