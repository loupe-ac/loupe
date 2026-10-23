<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;

/**
 * Records what stood open on a version at the moment its document was published, moved out of Draft: the decisions with no answer and the
 * threads that were not resolved. The caller owns the transaction and the flush.
 */
final readonly class DocumentEntryCheck
{
    public function __construct(
        private DecisionSummaryReader $decisionSummary,
        private CommentRepository $comments,
    ) {
    }

    public function record(Document $document, DocumentVersion $version): void
    {
        $version->entryUnansweredDecisions = ($this->decisionSummary)($document, $version)->unansweredCount();
        $version->entryOpenThreads = $this->comments->countOpenThreadsByVersion($version);
        $version->publishedAt = new \DateTimeImmutable();
    }
}
