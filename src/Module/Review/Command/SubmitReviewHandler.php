<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\DecisionSummaryReader;
use App\Module\Review\Service\DraftStateSignal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private ReviewRepository $reviews,
        private EventDispatcherInterface $events,
        private DraftStateSignal $draftState,
        private DecisionSummaryReader $decisionSummary,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): Review
    {
        if ($command->throughAgent && null !== $command->apiKey) {
            throw new \LogicException('A verdict is written through an agent or through an API key, never both.');
        }

        $verdict = Verdict::tryFrom($command->verdict)
            ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_invalid']);

        // Withdrawn is a verdict value but not a submittable one — it is written by
        // UndoVerdictHandler, which checks there is something to withdraw first.
        // Nothing else rejects it: the form DTO only guards against a blank value,
        // so a hand-crafted POST would otherwise reach the log through this route.
        if (Verdict::Withdrawn === $verdict) {
            throw new DomainErrors(['verdict' => 'review.document.flash.verdict_invalid']);
        }

        $note = trim($command->note ?? '');

        $document = $command->document;

        $result = $this->em->wrapInTransaction(function () use ($command, $document, $verdict, $note): Review|DomainErrors {
            // The version is read under the document's own row lock: a revision
            // landing between the read and the write would otherwise attach the
            // verdict to a version the reviewer never saw.
            $this->documents->lockForVerdict($document);

            $version = $this->documentVersions->findLatest($document);
            if ($version->versionNumber !== $command->versionNumber) {
                return new DomainErrors(['versionNumber' => 'review.document.flash.verdict_stale']);
            }

            $newest = $this->reviews->findNewestByVersion($version);
            $expectedReviewId = null === $command->expectedReviewId ? null : strtolower($command->expectedReviewId);
            if ($newest?->id?->toRfc4122() !== $expectedReviewId) {
                return new DomainErrors(['expectedReviewId' => 'review.document.flash.verdict_changed']);
            }

            // Read under the same lock as the write, so an answer cleared in
            // another tab cannot slip in between the check and the approval.
            if (Verdict::Approved === $verdict) {
                $unanswered = ($this->decisionSummary)($document, $version)->unansweredCount();
                if ($unanswered > 0) {
                    return new DomainErrors(
                        ['verdict' => 'review.document.flash.decisions_unanswered'],
                        ['verdict' => ['%count%' => $unanswered]],
                    );
                }
            }

            if (Verdict::ChangesRequested === $verdict && '' === $note && 0 === $this->comments->countOpenThreadsByVersion($version)) {
                return new DomainErrors(['note' => 'review.document.flash.note_or_comment_required']);
            }

            // Appended rather than replacing whatever stands: a version may be
            // approved, withdrawn and approved again, and the log keeps all three.
            $review = new Review(
                version: $version,
                verdict: $verdict,
                reviewer: $command->reviewer,
                sequence: null === $newest ? 1 : $newest->sequence + 1,
                note: '' === $note ? null : $note,
                workflowRule: $command->workflowRule,
                throughAgent: $command->throughAgent,
                apiKeyId: $command->apiKey?->keyId,
                apiKeyName: $command->apiKey?->keyName,
            );

            $document->status = $verdict->documentStatus();

            $this->em->persist($review);
            $this->events->dispatch(new ReviewSubmitted($review));
            $this->em->flush();

            return $review;
        });

        if ($result instanceof DomainErrors) {
            throw $result;
        }

        // Queued after the commit: a verdict dispatches ReviewSubmitted inside
        // its transaction, and the publisher sends whatever it holds.
        $this->draftState->send(
            $document->project->id ?? throw new \LogicException('A persisted project has an id.'),
            $document->id ?? throw new \LogicException('A persisted document has an id.'),
        );

        $this->auditor->record(
            'review.document_verdict_submitted',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'verdict' => $result->verdict->value,
                'reviewerId' => (string) $command->reviewer->id,
                ...($command->throughAgent ? ['throughAgent' => true] : []),
                ...(null !== $command->apiKey ? ['apiKeyId' => (string) $command->apiKey->keyId, 'apiKeyName' => $command->apiKey->keyName] : []),
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $result;
    }
}
