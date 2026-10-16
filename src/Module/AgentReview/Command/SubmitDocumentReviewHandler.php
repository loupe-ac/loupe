<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Exception\DomainErrors;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Service\WithdrawnWorkGuard;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Workflow\Contract\AgentReviewFailingSeverities;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores the review that a running design review worker of the card made of one version of a draft design of the card. */
final readonly class SubmitDocumentReviewHandler
{
    public const string REVIEW_WORK_KIND = 'design-review';

    public const string NO_REVIEW_RUN = 'agent_review.error.no_design_review_run';
    public const string NOT_LINKED = 'agent_review.error.document_not_linked';
    public const string NOT_DRAFT = 'agent_review.error.document_not_draft';
    public const string NO_SUCH_VERSION = 'agent_review.error.document_version_missing';

    public const string WORK_WITHDRAWN = 'agent_review.error.work_withdrawn';

    public function __construct(
        private WithdrawnWorkGuard $withdrawnWork,
        private WorkerRunRepository $workerRuns,
        private CardDocumentRepository $cardDocuments,
        private DocumentVersionRepository $documentVersions,
        private AgentReviewFailingSeverities $failingSeverities,
        private EntityManagerInterface $em,
        private CardEvaluations $evaluations,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SubmitDocumentReviewCommand $command): DocumentAgentReview
    {
        $card = $command->card;
        $document = $command->document;
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');

        $run = null === $command->sessionId ? null : $this->workerRuns->findOpenOfSessionForCard($card->project, $command->sessionId, $cardId, self::REVIEW_WORK_KIND);
        if (null === $run) {
            throw new DomainErrors(['session' => self::NO_REVIEW_RUN]);
        }
        if ($this->withdrawnWork->runIsWithdrawn($run)) {
            throw new DomainErrors(['session' => self::WORK_WITHDRAWN]);
        }
        if (!array_any($this->cardDocuments->findForDocument($document), static fn ($link): bool => $link->card->id?->equals($cardId) ?? false)) {
            throw new DomainErrors(['documentId' => self::NOT_LINKED]);
        }
        if (DocumentStatus::Draft !== $document->status) {
            throw new DomainErrors(['documentId' => self::NOT_DRAFT]);
        }
        if (null === $this->documentVersions->findByNumber($document, $command->versionNumber)) {
            throw new DomainErrors(['versionNumber' => self::NO_SUCH_VERSION]);
        }

        $failing = $this->failingSeverities->of($projectId);
        $fails = array_any($command->findings, static fn (AgentReviewFinding $finding): bool => !$finding->needsPerson && \in_array($finding->severity->value, $failing, true));

        $review = new DocumentAgentReview(
            project: $card->project,
            card: $card,
            document: $document,
            versionNumber: $command->versionNumber,
            summary: $command->summary,
            conclusion: $fails ? AgentReviewConclusion::Failure : AgentReviewConclusion::Success,
            findings: $command->findings,
            workerRunId: $run->id,
            workRequestId: $run->workRequestId,
        );
        $this->em->persist($review);
        $this->em->flush();

        $reviewId = $review->id ?? throw new \LogicException('A flushed review has an id.');
        $this->auditor->record(
            'agent_review.document_submitted',
            AuditOutcome::Success,
            [
                'reviewId' => (string) $reviewId,
                'cardId' => (string) $cardId,
                'projectId' => (string) $projectId,
                'documentId' => (string) $document->id,
                'versionNumber' => $command->versionNumber,
                'workerRunId' => (string) $run->id,
                'conclusion' => $review->conclusion->value,
                'findingCount' => \count($command->findings),
            ],
            new AuditSubject('document_agent_review', (string) $reviewId),
        );

        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$cardId]);
        }

        return $review;
    }
}
