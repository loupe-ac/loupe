<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Stores a failed review of a draft design, then reports the marker change that the next card evaluation reports.
 * Only the dev seed calls it, because a browser test has no design review worker.
 */
final readonly class SeedFailedDocumentReviewHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentAgentReviewRepository $documentAgentReviews,
        private CardDocumentRepository $cardDocuments,
        private EventDispatcherInterface $events,
    ) {
    }

    /** Answers null when no card links the document. */
    public function __invoke(SeedFailedDocumentReviewCommand $command): ?DocumentAgentReview
    {
        $document = $command->document;
        $card = array_first($this->cardDocuments->findForDocument($document))?->card;
        if (null === $card) {
            return null;
        }

        $failed = $this->documentAgentReviews->countRoundsByDocumentForCard($card)[(string) $document->id]['failed'] ?? 0;

        $review = new DocumentAgentReview(
            project: $card->project,
            card: $card,
            document: $document,
            versionNumber: $failed + 1,
            summary: 'Seeded failed review.',
            conclusion: AgentReviewConclusion::Failure,
            findings: [new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Seeded finding', 'A finding that fails the review.', AgentReviewCategory::Spec)],
        );
        $this->em->persist($review);
        $this->em->flush();

        $this->events->dispatch(new CardChanged(
            $card->project->id ?? throw new \LogicException('A stored project has an id.'),
            $card->id ?? throw new \LogicException('A stored card has an id.'),
            CardChanged::UPDATED,
            false,
            markersOnly: true,
        ));

        return $review;
    }
}
