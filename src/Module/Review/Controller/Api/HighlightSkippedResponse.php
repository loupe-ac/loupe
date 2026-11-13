<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

/** A quote that the call did not highlight. */
#[OA\Schema(required: ['quote', 'reason'])]
final readonly class HighlightSkippedResponse
{
    public function __construct(
        #[OA\Property(description: 'The quote as the request spelled it.')]
        public string $quote,

        #[OA\Property(description: '`blank` for an empty quote, `duplicate` for a quote that repeats an earlier one, or `not_found` for a quote that the document text does not hold.', enum: ['blank', 'duplicate', 'not_found'])]
        public string $reason,
    ) {
    }
}
