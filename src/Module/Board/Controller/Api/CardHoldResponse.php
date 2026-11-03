<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a hold. */
#[OA\Schema(required: ['cardId', 'url', 'outcome'])]
final readonly class CardHoldResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property(description: 'The card page in the app.', format: 'uri')]
        public string $url,

        #[OA\Property(enum: ['held'])]
        public string $outcome = 'held',
    ) {
    }
}
