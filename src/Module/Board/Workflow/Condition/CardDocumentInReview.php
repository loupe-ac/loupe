<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use Symfony\Component\Translation\TranslatableMessage;

/** A linked document with the tag is in review. */
final readonly class CardDocumentInReview implements Condition, MarkerEvidence, NamesMarkerSubject, ReadsDocumentTag
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.in_review';
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
        return [] !== self::inReview($facts, $params);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_document_in_review' : 'workflow.waiting.card_document_in_review', ['%tag%' => ParameterValue::string($params, 'tag')]);
    }

    /** The document that waited longest gives the evidence. A document with no version waited the least. */
    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $oldest = null;
        foreach (self::inReview($facts, $params) as $document) {
            if (null === $oldest || (null !== $document->versionAt && (null === $oldest->versionAt || $document->versionAt < $oldest->versionAt))) {
                $oldest = $document;
            }
        }

        return null === $oldest ? null : new Evidence(
            ['%title%' => $oldest->title ?? ''],
            new MarkerSubject(MarkerSubjectType::Document, ['document' => $oldest->id, 'version' => (string) ($oldest->version ?? 0)]),
        );
    }

    #[\Override]
    public function subjectType(): MarkerSubjectType
    {
        return MarkerSubjectType::Document;
    }

    #[\Override]
    public function documentTag(array $params): string
    {
        return ParameterValue::string($params, 'tag');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<DocumentFacts>
     */
    private static function inReview(Facts $facts, array $params): array
    {
        $tag = ParameterValue::string($params, 'tag');

        return array_values(array_filter(
            $facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => DocumentStatus::InReview->value === $document->status && \in_array($tag, $document->tags, true),
        ));
    }
}
