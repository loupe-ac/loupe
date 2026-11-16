<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\ParentDocumentsFacts;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The card has a document with the tag that is not approved, and the parent card links every such document.
 * The approvals that the card lacks are then exactly the approvals of documents that the parent shares.
 */
final readonly class CardDocumentSharedWithParentUnapproved implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.shared_with_parent_unapproved';
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
        return [DocumentsFacts::class, ParentDocumentsFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $unapproved = self::unapproved($facts, $params);
        if ([] === $unapproved) {
            return false;
        }
        $parentIds = array_map(static fn (DocumentFacts $document): string => $document->id, $facts->get(ParentDocumentsFacts::class)->documents);

        return array_all($unapproved, static fn (DocumentFacts $document): bool => \in_array($document->id, $parentIds, true));
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_document_shared_with_parent_unapproved' : 'workflow.waiting.card_document_shared_with_parent_unapproved', ['%tag%' => ParameterValue::string($params, 'tag')]);
    }

    /** The label names every document of the set, oldest wait first. The subject is the one that waited longest. */
    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $documents = self::unapproved($facts, $params);
        if ([] === $documents) {
            return null;
        }
        usort($documents, static fn (DocumentFacts $a, DocumentFacts $b): int => self::waitedSince($a) <=> self::waitedSince($b));

        return new Evidence(
            [
                '%count%' => \count($documents),
                '%titles%' => implode(', ', array_map(static fn (DocumentFacts $document): string => '"'.($document->title ?? '').'"', $documents)),
                '%title%' => $documents[0]->title ?? '',
            ],
            MarkerSubject::document($documents[0]),
        );
    }

    /** A document with no version waited the least. */
    private static function waitedSince(DocumentFacts $document): int
    {
        return $document->versionAt?->getTimestamp() ?? \PHP_INT_MAX;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<DocumentFacts>
     */
    private static function unapproved(Facts $facts, array $params): array
    {
        $tag = ParameterValue::string($params, 'tag');

        return array_values(array_filter(
            $facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => DocumentStatus::Approved->value !== $document->status && \in_array($tag, $document->tags, true),
        ));
    }
}
