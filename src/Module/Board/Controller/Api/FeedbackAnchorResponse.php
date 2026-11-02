<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** The element a note points at. */
#[OA\Schema(required: ['selector', 'text', 'quote', 'quotePrefix', 'quoteSuffix'])]
final readonly class FeedbackAnchorResponse
{
    public function __construct(
        public string $selector,
        public string $text,

        #[OA\Property(nullable: true)]
        public ?string $quote,

        #[OA\Property(nullable: true)]
        public ?string $quotePrefix,

        #[OA\Property(nullable: true)]
        public ?string $quoteSuffix,
    ) {
    }
}
