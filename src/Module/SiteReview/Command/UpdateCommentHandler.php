<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Context\CommentEditLockInterface;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Event\SiteReviewCommentUpdated;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private CommentEditLockInterface $editLock,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @throws CommentNotFound when the note is not a pending note of the project
     * @throws CommentLocked   when the card of the note started work
     */
    public function __invoke(UpdateCommentCommand $command): SiteReviewComment
    {
        // A refusal leaves the closure as a value, because a throw closes the
        // EntityManager. The project lock orders the check against a card move.
        $comment = $this->em->wrapInTransaction(function () use ($command): SiteReviewComment|CommentNotFound|CommentLocked {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $comment = $this->siteReviewComments->findOnePending($command->commentId, $command->project);
            if (null === $comment) {
                return CommentNotFound::forId($command->commentId);
            }
            if ($this->editLock->isLocked($comment)) {
                return CommentLocked::forId($command->commentId);
            }

            // Mutable: the widget can edit the body while the comment is still Pending.
            $comment->body = $command->body;

            return $comment;
        });

        if ($comment instanceof \Throwable) {
            throw $comment;
        }

        $this->auditor->record(
            'site_review.comment_updated',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'commentId' => (string) $command->commentId,
            ],
            new AuditSubject('site_review_comment', (string) $command->commentId),
        );

        $this->events->dispatch(new SiteReviewCommentUpdated($command->project->requireId(), $command->commentId, $comment->body));

        return $comment;
    }
}
