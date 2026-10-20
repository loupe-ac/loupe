<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Module\SiteReview\Service\DecodedScreenshot;

final readonly class AddCommentCommand
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
        public ?string $context = null,
        public ?string $deliveryId = null,
        /**
         * Opaque to this module. It joins the delivery hash, so a retry of a
         * known delivery id with another scope is a conflict.
         */
        public ?string $deliveryScope = null,
        public ReviewSurface $surface = ReviewSurface::Web,
        /** @var array<string, mixed>|null the facts of a native note */
        public ?array $native = null,
        public ?DecodedScreenshot $screenshot = null,
    ) {
    }
}
