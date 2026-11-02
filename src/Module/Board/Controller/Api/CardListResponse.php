<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One page of cards, as the spec shows it. Page::toResponse() builds the real body. */
#[OA\Schema(description: 'The rows are summaries, or whole cards with `full=true`.', required: ['items', 'next'])]
final readonly class CardListResponse
{
    /** @param list<CardSummaryResponse|CardResponse> $items */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(oneOf: [
            new OA\Schema(ref: new Model(type: CardSummaryResponse::class)),
            new OA\Schema(ref: new Model(type: CardResponse::class)),
        ]))]
        public array $items,

        #[OA\Property(description: 'The URL of the next page, or null on the last page.', format: 'uri', nullable: true)]
        public ?string $next,
    ) {
    }
}
