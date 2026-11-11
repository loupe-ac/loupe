<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The placed block, with the card just above it and the card just below it. */
#[OA\Schema(required: ['above', 'cards', 'below'])]
final readonly class ReorderProjectCardsResponse
{
    /** @param list<PlacedCardResponse> $cards */
    public function __construct(
        #[OA\Property(ref: new Model(type: PlacedCardResponse::class), description: 'The card just above the block, or null when the block is first.', nullable: true)]
        public ?PlacedCardResponse $above,

        #[OA\Property(description: 'The block, top first.', type: 'array', items: new OA\Items(ref: new Model(type: PlacedCardResponse::class)))]
        public array $cards,

        #[OA\Property(ref: new Model(type: PlacedCardResponse::class), description: 'The card just below the block, or null when the block is last.', nullable: true)]
        public ?PlacedCardResponse $below,
    ) {
    }
}
