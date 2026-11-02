<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** A card type of a project, as the column list shows it. */
#[OA\Schema(required: ['key', 'label', 'children', 'lane'])]
final readonly class CardTypeResponse
{
    public function __construct(
        #[OA\Property(description: 'The value you pass as `type`.')]
        public string $key,
        public string $label,

        #[OA\Property(description: 'A card of this type may be the parent of other cards.')]
        public bool $children,

        #[OA\Property(description: 'A card of this type gets a lane on the board.')]
        public bool $lane,
    ) {
    }
}
