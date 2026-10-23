<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The newest review of the newest version of a linked document with the tag is a withdrawal of an approval.
 * It is not a ReadsDocumentTag: the rules that undo a design sit in every column, and their tags must not become tags of a stage.
 */
final readonly class CardDocumentApprovalWithdrawn implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.approval_withdrawn';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('tag', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [DocumentsFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $tag = ParameterValue::string($params, 'tag');

        return array_any(
            $facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => $document->newestReviewWithdrewApproval && \in_array($tag, $document->tags, true) && $facts->readsDocument($document->id),
        );
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage(
            $negated ? 'workflow.waiting.not.card_document_approval_withdrawn' : 'workflow.waiting.card_document_approval_withdrawn',
            ['%tag%' => ParameterValue::string($params, 'tag')],
        );
    }
}
