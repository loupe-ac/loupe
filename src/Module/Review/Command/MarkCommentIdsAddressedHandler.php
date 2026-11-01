<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\CommentRepository;
use App\Security\McpBoundProjectVoter;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Marks comments addressed from the ids a caller sent and reports one result
 * per id. The batch write stays in MarkCommentsAddressedHandler.
 */
final readonly class MarkCommentIdsAddressedHandler
{
    public function __construct(
        private CommentRepository $comments,
        private MarkCommentsAddressedHandler $markCommentsAddressed,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MarkCommentIdsAddressedCommand $command): MarkCommentIdsAddressedResult
    {
        /** @var list<array{id: string, reason: ?string}> $plan one entry per id, in the order given */
        $plan = [];
        /** @var list<Comment> $resolved the ids that resolved, in the same order */
        $resolved = [];

        foreach ($command->commentIds as $id) {
            if (!Uuid::isValid($id)) {
                $plan[] = ['id' => $id, 'reason' => 'invalid_id'];
                continue;
            }

            $comment = $this->comments->find(Uuid::fromString($id));

            // Same reason for "does not exist" and "another project", so the
            // tolerant shape cannot be used to probe what exists.
            if (null === $comment || $comment->scopedProject() !== $command->project) {
                if (null !== $comment) {
                    $this->recordForeign($comment, $command->project);
                }
                $plan[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            $resolved[] = $comment;
            $plan[] = ['id' => $id, 'reason' => null];
        }

        $outcomes = ($this->markCommentsAddressed)(new MarkCommentsAddressedCommand($resolved));

        $addressed = [];
        $skipped = [];
        $next = 0;
        foreach ($plan as $entry) {
            $reason = $entry['reason'];

            if (null === $reason) {
                // No default arm: an outcome added later must be an unhandled
                // match here rather than be silently reported as addressed.
                $reason = match ($outcomes[$next++] ?? throw new \LogicException('handler returned fewer outcomes than comments')) {
                    MarkCommentAddressedOutcome::Addressed => null,
                    MarkCommentAddressedOutcome::Superseded => 'superseded',
                    MarkCommentAddressedOutcome::IsReply => 'is_reply',
                    MarkCommentAddressedOutcome::AlreadyAddressed => 'already_addressed',
                    MarkCommentAddressedOutcome::AlreadyResolved => 'already_resolved',
                    MarkCommentAddressedOutcome::NotFound => 'not_found',
                    MarkCommentAddressedOutcome::Deleted => 'deleted',
                };
            }

            if (null === $reason) {
                $addressed[] = $entry['id'];
                continue;
            }

            $skipped[] = ['id' => $entry['id'], 'reason' => $reason];
        }

        return new MarkCommentIdsAddressedResult($addressed, $skipped);
    }

    private function recordForeign(Comment $comment, Project $bound): void
    {
        $this->auditor->record(
            'review.mcp_access_denied',
            AuditOutcome::Refused,
            [
                'attribute' => McpBoundProjectVoter::COMMENT_WRITE,
                'subjectId' => (string) $comment->id,
                'subjectProjectId' => (string) $comment->scopedProject()->id,
                'boundProjectId' => (string) $bound->id,
            ],
            new AuditSubject($comment->scopedSubjectType(), (string) $comment->id),
            Auditor::CATEGORY_SECURITY,
        );
    }
}
