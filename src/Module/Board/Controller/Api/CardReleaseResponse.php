<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of the end of a hold. */
#[OA\Schema(required: ['cardId', 'url', 'outcome'])]
final readonly class CardReleaseResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property(description: 'The card page in the app.', format: 'uri')]
        public string $url,

        #[OA\Property(enum: ['released'])]
        public string $outcome = 'released',
    ) {
    }
}
