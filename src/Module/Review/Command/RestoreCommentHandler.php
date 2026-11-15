<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Event\CommentThreadChanged;
use App\Module\Review\Event\CommentThreadScope;
use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RestoreCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CommentRepository $comments,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(RestoreCommentCommand $command): void
    {
        $comment = $command->comment;
        if (null !== $comment->parent) {
            throw new DomainErrors(['deletionSequence' => 'comment.error.thread_required']);
        }

        $restored = $this->em->wrapInTransaction(function () use ($command, $comment): bool|DomainErrors {
            if (!$this->comments->lockAndRefreshState($comment)) {
                return new DomainErrors(['deletionSequence' => 'comment.error.not_found']);
            }
            if ($command->deletionSequence < 1 || $command->deletionSequence !== $comment->deletionSequence) {
                return new DomainErrors(['deletionSequence' => 'comment.error.stale_deletion']);
            }
            if (null === $comment->deletedAt) {
                return false;
            }
            $comment->deletedAt = null;

            return true;
        });
        if ($restored instanceof DomainErrors) {
            throw $restored;
        }
        if (!$restored) {
            return;
        }

        $this->auditor->record(
            'review.comment_restored',
            AuditOutcome::Success,
            [
                'commentId' => (string) $comment->id,
                'documentId' => (string) $comment->version->document->id,
                'versionNumber' => $comment->version->versionNumber,
                'deletionSequence' => $command->deletionSequence,
            ],
            new AuditSubject('comment', (string) $comment->id),
        );

        $this->events->dispatch(new CommentThreadChanged(
            $comment->version->document->project->id ?? throw new \LogicException('A persisted project has an id.'),
            $comment->version->document->id ?? throw new \LogicException('A persisted document has an id.'),
            $comment->id ?? throw new \LogicException('A persisted comment has an id.'),
            CommentThreadScope::Collection,
        ));
    }
}
