<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Exception\DomainErrors;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use Symfony\Component\Uid\Uuid;

final readonly class PageFeedbackHandler
{
    public const string CARD_NOT_FOUND = 'board.feedback.error.card_not_found';

    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
    ) {
    }

    /** @throws DomainErrors when the card filter names a card that is not in the project */
    public function __invoke(PageFeedbackCommand $command): PageFeedbackView
    {
        $ids = null;
        if (null !== $command->cardId) {
            $card = $this->cards->findByIdsInProject($command->project, [$command->cardId])[0]
                ?? throw new DomainErrors(['cardId' => self::CARD_NOT_FOUND]);
            $ids = array_map(
                static fn ($link): Uuid => $link->comment->id ?? throw new \LogicException('A stored comment has an id.'),
                $this->cardSiteReviewComments->findForCard($card),
            );
        }

        $rows = $this->siteReviewComments->findPageForProject(
            $command->project,
            $command->status,
            $ids,
            $command->page->fetchSize(),
            $command->sort->newestFirst(),
            $command->page->cursor?->createdPosition(),
        );
        $page = Page::fromRows($rows, $command->page, fn (SiteReviewComment $comment): Cursor => Cursor::afterCreated(
            $command->sort->value,
            $comment->createdAt,
            $comment->id ?? throw new \LogicException('A stored comment has an id.'),
            $command->filters,
        ));

        return new PageFeedbackView($page, $this->cardSiteReviewComments->findForComments($page->items), $this->siteReviewScreenshots->findSizesByComments($page->items));
    }
}
