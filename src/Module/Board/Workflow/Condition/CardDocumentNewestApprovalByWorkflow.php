<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;

/**
 * The newest approval among the approved documents with the tag came from the Workflow account.
 * When several documents share that newest time, one approval by the Workflow account is enough.
 * No approved document with the tag and an approval fails it.
 */
final readonly class CardDocumentNewestApprovalByWorkflow extends EntryCheckCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.newest_approval_by_workflow';
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $newestAt = self::newestApprovalAt($this->tagged($facts, $params));
        if (null === $newestAt) {
            return false;
        }

        return array_any(
            $this->tagged($facts, $params),
            static fn (DocumentFacts $document): bool => DocumentStatus::Approved->value === $document->status
                && $document->newestApprovalByWorkflow
                && null !== $document->newestApprovalAt
                && 0 === ($document->newestApprovalAt <=> $newestAt),
        );
    }

    /**
     * The newest approval time of the approved documents in the list, or null when none has one.
     *
     * @param list<DocumentFacts> $documents
     */
    public static function newestApprovalAt(array $documents): ?\DateTimeImmutable
    {
        $newest = null;
        foreach ($documents as $document) {
            if (DocumentStatus::Approved->value === $document->status && null !== $document->newestApprovalAt && (null === $newest || $document->newestApprovalAt > $newest)) {
                $newest = $document->newestApprovalAt;
            }
        }

        return $newest;
    }
}
