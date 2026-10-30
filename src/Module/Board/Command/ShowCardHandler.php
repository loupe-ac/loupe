<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardArtifactRepository;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\CardValueReader;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use App\Module\Workflow\Contract\CardTypeCatalog;

final readonly class ShowCardHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardLinkRepository $cardLinks,
        private CardRepository $cards,
        private CardPullRequestStates $pullRequestStates,
        private ShowCardHistoryHandler $history,
        private CardTypeCatalog $catalog,
        private CardAgentReviews $agentReviews,
        private CardArtifactRepository $cardArtifacts,
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
        private CardValueReader $cardValues,
    ) {
    }

    public function __invoke(ShowCardCommand $command): CardView
    {
        $children = [];
        $progress = null;
        if ($this->catalog->forProject($command->card->project->requireId())->get($command->card->type)->children) {
            $children = $this->cards->findChildren($command->card);
            $progress = new CardProgress(
                \count(array_filter($children, static fn (Card $child): bool => $child->column->terminal)),
                \count($children),
            );
        }

        $pullRequestStates = $this->pullRequestStates->forCards([$command->card]);
        $cardId = (string) ($command->card->id ?? throw new \LogicException('A stored card has an id.'));

        $links = $this->cardSiteReviewComments->findForCard($command->card);

        return new CardView(
            $command->card,
            $links,
            array_map(
                static fn (CardLink $link): RelatedCard => new RelatedCard($link->otherThan($command->card), $link->kindFor($command->card)),
                $this->cardLinks->findForCard($command->card),
            ),
            $pullRequestStates,
            ($this->history)(new ShowCardHistoryCommand($command->card)),
            $children,
            $progress,
            $command->card->column->terminal ? new StoredCardMarkers([]) : StoredCardMarkers::of($command->card),
            $this->agentReviews->forCards([$command->card]),
            $this->cardArtifacts->findWithLatestVersionForCards([$command->card])[$cardId] ?? [],
            $this->siteReviewScreenshots->findSizesByComments(array_map(static fn (CardSiteReviewComment $link): SiteReviewComment => $link->comment, $links)),
            $this->cardValues->forCard($command->card),
        );
    }
}
