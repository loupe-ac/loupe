<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use Symfony\Component\Uid\Uuid;

final readonly class ShowFeedbackHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
    ) {
    }

    /** Null for an id that is no uuid, and for an item of another project. */
    public function __invoke(ShowFeedbackCommand $command): ?ShowFeedbackView
    {
        if (!Uuid::isValid($command->feedbackId)) {
            return null;
        }

        $comment = $this->siteReviewComments->findOneByIdAndProjectId($command->feedbackId, (string) $command->project->id);
        if (null === $comment) {
            return null;
        }

        $key = (string) $comment->id;

        return new ShowFeedbackView(
            $comment,
            $this->cardSiteReviewComments->findForComments([$comment])[$key] ?? null,
            $this->siteReviewScreenshots->findSizesByComments([$comment])[$key] ?? null,
        );
    }
}
