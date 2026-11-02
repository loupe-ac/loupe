<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Command\RelatedCard;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardArtifact;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Service\PullRequestStates;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Board\View\CardValueView;

/**
 * Where a card row reads the data that its plain fields do not hold.
 *
 * Each source is a closure, and a closure for a whole page reads the page once on
 * its first call. A row that never names a field never calls its source.
 *
 * @phpstan-import-type AgentReviewSummary from \App\Module\Board\Service\CardAgentReviews
 * @phpstan-import-type ScreenshotSize from CardPayload
 */
final readonly class CardSources
{
    /**
     * @param \Closure(Card): list<CardSiteReviewComment>                         $comments
     * @param \Closure(Card): list<RelatedCard>                                   $related
     * @param \Closure(Card): list<Card>                                          $children         empty for a card whose type may not have children
     * @param \Closure(): PullRequestStates                                       $states
     * @param \Closure(): array<string, AgentReviewSummary>                       $reviews          keyed by pull request link id
     * @param \Closure(Card): ?CardPause                                          $pause
     * @param \Closure(Card): StoredCardMarkers                                   $markers
     * @param \Closure(Card): list<array{link: CardArtifact, versionNumber: int}> $artifacts
     * @param \Closure(): array<string, ScreenshotSize>                           $sizes            the screenshot size of each comment that has one, keyed by comment id
     * @param \Closure(Card): list<CardValueView>                                 $values
     * @param \Closure(): void                                                    $loadPullRequests
     * @param \Closure(): void                                                    $loadDocuments
     * @param \Closure(): void                                                    $loadParents
     */
    public function __construct(
        public \Closure $comments,
        public \Closure $related,
        public \Closure $children,
        public \Closure $states,
        public \Closure $reviews,
        public \Closure $pause,
        public \Closure $markers,
        public \Closure $artifacts,
        public \Closure $sizes,
        public \Closure $values,
        public \Closure $loadPullRequests,
        public \Closure $loadDocuments,
        public \Closure $loadParents,
    ) {
    }
}
