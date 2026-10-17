<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentAgentReviews;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @phpstan-import-type DocumentAgentReviewSummary from DocumentAgentReviews
 */
#[AsAlias(DocumentAgentReviews::class)]
final readonly class DocumentAgentReviewReader implements DocumentAgentReviews
{
    public function __construct(
        private DocumentAgentReviewRepository $documentAgentReviews,
    ) {
    }

    #[\Override]
    public function forDocument(Document $document): array
    {
        return array_map($this->summary(...), $this->documentAgentReviews->findOfDocument($document));
    }

    /**
     * @return DocumentAgentReviewSummary
     */
    private function summary(DocumentAgentReview $review): array
    {
        $answers = [];
        foreach ($review->answers() as $answer) {
            $answers[$answer->index] = ['answer' => $answer->answer->value, 'reason' => $answer->reason];
        }

        $findings = [];
        foreach ($review->findings() as $index => $finding) {
            $findings[] = [
                'index' => $index,
                'severity' => $finding->severity->value,
                'title' => $finding->title,
                'body' => $finding->body,
                'needsPerson' => $finding->needsPerson,
                'answer' => $answers[$index] ?? null,
            ];
        }

        return [
            'reviewId' => (string) $review->id,
            'cardId' => (string) $review->card->id,
            'versionNumber' => $review->versionNumber,
            'conclusion' => $review->conclusion->value,
            'summary' => $review->summary,
            'findings' => $findings,
            'answered' => $review->isAnswered(),
            'answeredAt' => $review->answeredAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $review->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
