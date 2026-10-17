<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;

/**
 * Reads the agent reviews of a document, newest first, each with the card it belongs to.
 * The AgentReview module implements it.
 *
 * @phpstan-type DocumentAgentReviewFinding array{index: int, severity: string, title: string, body: string, needsPerson: bool, answer: ?array{answer: string, reason: ?string}}
 * @phpstan-type DocumentAgentReviewSummary array{reviewId: string, cardId: string, versionNumber: int, conclusion: string, summary: string, findings: list<DocumentAgentReviewFinding>, answered: bool, answeredAt: ?string, createdAt: string}
 */
interface DocumentAgentReviews
{
    /** @return list<DocumentAgentReviewSummary> */
    public function forDocument(Document $document): array;
}
