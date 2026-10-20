<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\NewScreenshot;
use App\Module\SiteReview\Command\NewStroke;
use App\Module\SiteReview\Entity\ReviewSurface;

final readonly class AddFeedbackCommand
{
    /**
     * @param list<NewAnchor> $anchors an empty list is an unanchored page note
     * @param list<NewStroke> $strokes freehand drawing over the page, if any
     */
    public function __construct(
        public Project $project,
        /** @phpstan-var non-empty-string */
        public string $body,
        /** Null for a native note. */
        public ?string $url,
        public array $anchors = [],
        public array $strokes = [],
        public ?string $deliveryId = null,
        /** An open card of the project. Null creates a card from the note. */
        public ?string $cardId = null,
        /** The epic a created card goes under. Read only when $cardId is null. */
        public ?string $parentCardId = null,
        /** What the embed's `data-context` said the page was, stored as given. */
        public ?string $context = null,
        public ReviewSurface $surface = ReviewSurface::Web,
        /** @var array<string, mixed>|null the facts of a native note */
        public ?array $native = null,
        public ?NewScreenshot $screenshot = null,
    ) {
    }
}
