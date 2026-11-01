<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Event\VerdictWithdrawn;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UndoVerdictHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentVersionRepository $documentVersions,
        private ReviewRepository $reviews,
        private EventDispatcherInterface $events,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UndoVerdictCommand $command): Review
    {
        $document = $command->document;

        $result = $this->em->wrapInTransaction(function () use ($command, $document): Review|DomainErrors {
            // Same lock SubmitReviewHandler takes, and needed for the same two
            // reasons: a revision must not land between reading the version and
            // writing to it, and the next sequence number must be read and used
            // without another append slipping in between.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($document);
            if (null !== $command->expectedVersionNumber && $version->versionNumber !== $command->expectedVersionNumber) {
                return new DomainErrors(['versionNumber' => 'review.document.flash.verdict_stale']);
            }
            $newest = $this->reviews->findNewestByVersion($version);
            if (null === $newest) {
                return new DomainErrors(['reviewId' => 'review.document.flash.verdict_none']);
            }

            if (Verdict::Withdrawn === $newest->verdict) {
                return new DomainErrors(['reviewId' => 'review.document.flash.verdict_already_withdrawn']);
            }

            if ($newest->id?->toRfc4122() !== strtolower($command->reviewId)) {
                return new DomainErrors(['reviewId' => 'review.document.flash.verdict_changed']);
            }

            // A withdrawal is appended, never a deletion or an edit of the verdict it
            // takes back: the log is what says the document was approved at one point
            // and by whom, and both of those are answers a reader wants later.
            $withdrawal = new Review(
                version: $version,
                verdict: Verdict::Withdrawn,
                reviewer: $command->actor,
                sequence: $newest->sequence + 1,
                throughAgent: $command->throughAgent,
            );

            $document->status = Verdict::Withdrawn->documentStatus();

            $this->em->persist($withdrawal);
            $this->events->dispatch(new VerdictWithdrawn($withdrawal, $newest));
            $this->em->flush();

            return $withdrawal;
        });

        if ($result instanceof DomainErrors) {
            throw $result;
        }

        $this->events->dispatch(new DocumentStatusChanged(
            $document->project->id ?? throw new \LogicException('A persisted project has an id.'),
            $document->id ?? throw new \LogicException('A persisted document has an id.'),
        ));

        $this->auditor->record(
            'review.document_verdict_undone',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'status' => $document->status->value,
                ...($command->throughAgent ? ['throughAgent' => true] : []),
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $result;
    }
}
