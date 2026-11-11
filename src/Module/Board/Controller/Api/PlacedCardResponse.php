<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** A card of a reorder answer, with the rank it holds now. */
#[OA\Schema(required: ['number', 'title', 'rank'])]
final readonly class PlacedCardResponse
{
    public function __construct(
        #[OA\Property(description: 'The card number.', minimum: 1)]
        public int $number,
        public string $title,

        #[OA\Property(description: 'The rank in the column, counting from 0 at the top.', minimum: 0)]
        public int $rank,
    ) {
    }
}
