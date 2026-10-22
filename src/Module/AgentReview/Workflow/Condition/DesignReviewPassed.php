<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\DocumentReviewConclusionFacts;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ListsDocuments;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The newest agent review of each document with the tag in review is a success. A document with no agent review fails it,
 * and so does a card with no document in review. A marker that binds a document reads that document alone.
 */
final readonly class DesignReviewPassed implements Condition, ReadsDocumentTag
{
    #[\Override]
    public static function key(): string
    {
        return 'design_review.passed';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.agent_review';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('tag', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [ListsDocuments::class, DocumentReviewConclusionFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $tag = ParameterValue::string($params, 'tag');
        $documents = array_filter(
            $facts->documents(),
            static fn (DocumentFacts $document): bool => DocumentStatus::InReview->value === $document->status && \in_array($tag, $document->tags, true) && $facts->readsDocument($document->id),
        );
        $newest = $facts->get(DocumentReviewConclusionFacts::class)->newestByDocument;

        return [] !== $documents && array_all($documents, static fn (DocumentFacts $document): bool => AgentReviewConclusion::Success === ($newest[$document->id] ?? null));
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.design_review_passed' : 'workflow.waiting.design_review_passed', ['%tag%' => ParameterValue::string($params, 'tag')]);
    }

    #[\Override]
    public function documentTag(array $params): string
    {
        return ParameterValue::string($params, 'tag');
    }
}
