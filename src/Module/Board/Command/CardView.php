<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardArtifact;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Board\Service\PullRequestStates;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Board\View\CardValueView;

/**
 * Everything one card page renders.
 *
 * @phpstan-import-type AgentReviewSummary from CardAgentReviews
 */
final readonly class CardView
{
    /**
     * @param list<CardSiteReviewComment>                         $siteReviewLinks
     * @param list<RelatedCard>                                   $relatedCards
     * @param list<Card>                                          $children        empty for a card that is not an epic
     * @param ?CardProgress                                       $progress        null for a card that is not an epic
     * @param array<string, AgentReviewSummary>                   $agentReviews    keyed by the id of the pull request link, with no entry for a link that has no review
     * @param list<array{link: CardArtifact, versionNumber: int}> $artifacts       in link order
     * @param array<string, array{width: int, height: int}>       $screenshotSizes keyed by comment id, with no entry for a note that has no screenshot
     * @param list<CardValueView>                                 $values          the keys the workflow template declares, empty when it declares none
     */
    public function __construct(
        public Card $card,
        public array $siteReviewLinks,
        public array $relatedCards,
        public PullRequestStates $pullRequestStates,
        public CardHistoryView $history,
        public array $children = [],
        public ?CardProgress $progress = null,
        public StoredCardMarkers $markers = new StoredCardMarkers([]),
        public array $agentReviews = [],
        public array $artifacts = [],
        public array $screenshotSizes = [],
        public array $values = [],
    ) {
    }
}
