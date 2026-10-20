<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Command\ListSiteReviewCommentsCommand;
use App\Module\SiteReview\Command\ListSiteReviewCommentsHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;

final readonly class ListFeedbackHandler
{
    public function __construct(
        private ListSiteReviewCommentsHandler $listComments,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
    ) {
    }

    public function __invoke(ListFeedbackCommand $command): ListFeedbackView
    {
        $feedback = ($this->listComments)(new ListSiteReviewCommentsCommand($command->project, $command->status))->comments;
        $links = $this->cardSiteReviewComments->findForComments($feedback);

        if (null !== $command->card) {
            $cardId = $command->card->id;
            $feedback = array_values(array_filter(
                $feedback,
                static fn (SiteReviewComment $comment): bool => ($links[(string) $comment->id] ?? null)?->card->id?->equals($cardId) ?? false,
            ));
            $links = array_filter($links, static fn (CardSiteReviewComment $link): bool => $link->card->id?->equals($cardId) ?? false);
        }

        return new ListFeedbackView($feedback, $links, $this->siteReviewScreenshots->findSizesByComments($feedback));
    }
}
