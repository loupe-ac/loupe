<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Exception\DomainErrors;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Entity\DocumentReviewAnswer;
use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\AgentReview\Service\WithdrawnWorkGuard;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores the answer of a running fix worker of the card to each finding of the latest agent review of a design of the card. */
final readonly class AnswerDocumentReviewHandler
{
    public const array FIX_WORK_KINDS = ['product-design-revise', 'tech-design-revise'];

    public const string NO_FIX_RUN = 'agent_review.error.no_design_fix_run';
    public const string NOT_LINKED = 'agent_review.error.document_not_linked';
    public const string NO_REVIEW = 'agent_review.error.document_has_no_review';
    public const string ALREADY_ANSWERED = 'agent_review.error.document_review_answered';
    public const string UNKNOWN_FINDING = 'agent_review.error.answer_names_unknown_finding';
    public const string DUPLICATE_ANSWER = 'agent_review.error.answer_repeats_finding';
    public const string MISSING_ANSWER = 'agent_review.error.finding_has_no_answer';
    public const string REASON_REQUIRED = 'agent_review.error.not_changed_needs_reason';
    public const string OWNER_FINDING = 'agent_review.error.owner_finding_goes_to_owner';

    public const string WORK_WITHDRAWN = 'agent_review.error.work_withdrawn';

    public function __construct(
        private WithdrawnWorkGuard $withdrawnWork,
        private WorkerRunRepository $workerRuns,
        private CardDocumentRepository $cardDocuments,
        private DocumentAgentReviewRepository $documentAgentReviews,
        private EntityManagerInterface $em,
        private CardEvaluations $evaluations,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AnswerDocumentReviewCommand $command): DocumentAgentReview
    {
        $card = $command->card;
        $document = $command->document;
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');

        $run = null;
        if (null !== $command->sessionId) {
            foreach (self::FIX_WORK_KINDS as $kind) {
                $run ??= $this->workerRuns->findOpenOfSessionForCard($card->project, $command->sessionId, $cardId, $kind);
            }
        }
        if (null === $run) {
            throw new DomainErrors(['session' => self::NO_FIX_RUN]);
        }
        if ($this->withdrawnWork->runIsWithdrawn($run)) {
            throw new DomainErrors(['session' => self::WORK_WITHDRAWN]);
        }
        if (!array_any($this->cardDocuments->findForDocument($document), static fn ($link): bool => $link->card->id?->equals($cardId) ?? false)) {
            throw new DomainErrors(['documentId' => self::NOT_LINKED]);
        }
        $review = $this->documentAgentReviews->findLatestOfDocumentForCard($document, $card) ?? throw new DomainErrors(['documentId' => self::NO_REVIEW]);
        if ($review->isAnswered()) {
            throw new DomainErrors(['documentId' => self::ALREADY_ANSWERED]);
        }

        $this->assertAnswersCover($review->findings(), $command->answers);

        $answers = $command->answers;
        usort($answers, static fn (DocumentReviewFindingAnswer $a, DocumentReviewFindingAnswer $b): int => $a->index <=> $b->index);
        $review->answer($answers);
        $this->em->flush();

        $reviewId = $review->id ?? throw new \LogicException('A flushed review has an id.');
        $this->auditor->record(
            'agent_review.document_answered',
            AuditOutcome::Success,
            [
                'reviewId' => (string) $reviewId,
                'cardId' => (string) $cardId,
                'projectId' => (string) $projectId,
                'documentId' => (string) $document->id,
                'workerRunId' => (string) $run->id,
                'answerCount' => \count($answers),
            ],
            new AuditSubject('document_agent_review', (string) $reviewId),
        );

        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$cardId]);
        }

        return $review;
    }

    /**
     * @param list<AgentReviewFinding>          $findings
     * @param list<DocumentReviewFindingAnswer> $answers
     */
    private function assertAnswersCover(array $findings, array $answers): void
    {
        $findingCount = \count($findings);
        $seen = [];
        foreach ($answers as $answer) {
            if ($answer->index < 0 || $answer->index >= $findingCount) {
                throw new DomainErrors(['answers' => self::UNKNOWN_FINDING]);
            }
            if (isset($seen[$answer->index])) {
                throw new DomainErrors(['answers' => self::DUPLICATE_ANSWER]);
            }
            $seen[$answer->index] = true;
            if (DocumentReviewAnswer::NotChanged === $answer->answer && '' === trim($answer->reason ?? '')) {
                throw new DomainErrors(['answers' => self::REASON_REQUIRED]);
            }
            if ($findings[$answer->index]->needsPerson && DocumentReviewAnswer::ToOwner !== $answer->answer) {
                throw new DomainErrors(['answers' => self::OWNER_FINDING]);
            }
        }
        if (\count($seen) !== $findingCount) {
            throw new DomainErrors(['answers' => self::MISSING_ANSWER]);
        }
    }
}
