<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Security\McpBoundProjectVoter;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Marks feedback addressed from the ids a caller sent and reports one result
 * per id. The batch write stays in MarkSiteReviewCommentsAddressedHandler.
 */
final readonly class MarkSiteReviewCommentIdsAddressedHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private MarkSiteReviewCommentsAddressedHandler $markCommentsAddressed,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MarkSiteReviewCommentIdsAddressedCommand $command): MarkSiteReviewCommentIdsAddressedResult
    {
        /** @var list<array{id: string, reason: ?string}> $plan one entry per id, in the order given */
        $plan = [];
        /** @var list<SiteReviewComment> $resolved the ids that resolved, in the same order */
        $resolved = [];

        foreach ($command->commentIds as $id) {
            try {
                $uuid = Uuid::fromString($id);
            } catch (\InvalidArgumentException) {
                $plan[] = ['id' => $id, 'reason' => 'invalid_id'];
                continue;
            }

            // Same answer for "does not exist" and "another project", so the
            // tolerant shape cannot be used to probe what exists.
            $comment = $this->siteReviewComments->find($uuid);
            if (null === $comment || $comment->scopedProject() !== $command->project) {
                if (null !== $comment) {
                    $this->recordForeign($comment, $command->project);
                }
                $plan[] = ['id' => $id, 'reason' => 'unknown'];
                continue;
            }

            $resolved[] = $comment;
            $plan[] = ['id' => $id, 'reason' => null];
        }

        $outcomes = ($this->markCommentsAddressed)(new MarkSiteReviewCommentsAddressedCommand($resolved));

        $addressed = [];
        $skipped = [];
        $next = 0;
        foreach ($plan as $entry) {
            $reason = $entry['reason'] ?? match ($outcomes[$next++] ?? throw new \LogicException('handler returned fewer outcomes than comments')) {
                MarkSiteReviewCommentAddressedOutcome::Addressed => null,
                MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed => 'already_addressed',
                MarkSiteReviewCommentAddressedOutcome::AlreadyResolved => 'resolved',
                MarkSiteReviewCommentAddressedOutcome::NotFound => 'unknown',
            };

            if (null === $reason) {
                $addressed[] = $entry['id'];
                continue;
            }

            $skipped[] = ['id' => $entry['id'], 'reason' => $reason];
        }

        return new MarkSiteReviewCommentIdsAddressedResult($addressed, $skipped);
    }

    private function recordForeign(SiteReviewComment $comment, Project $bound): void
    {
        $this->auditor->record(
            'site_review.mcp_access_denied',
            AuditOutcome::Refused,
            [
                'attribute' => McpBoundProjectVoter::SITE_REVIEW_WRITE,
                'subjectId' => (string) $comment->id,
                'subjectProjectId' => (string) $comment->scopedProject()->id,
                'boundProjectId' => (string) $bound->id,
            ],
            new AuditSubject($comment->scopedSubjectType(), (string) $comment->id),
            Auditor::CATEGORY_SECURITY,
        );
    }
}
