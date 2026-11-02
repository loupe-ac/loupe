<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One page of columns, as the spec shows it. Page::toResponse() builds the real body. */
#[OA\Schema(required: ['items', 'next', 'types', 'defaultType'])]
final readonly class BoardColumnListResponse
{
    /**
     * @param list<BoardColumnResponse> $items
     * @param list<CardTypeResponse>    $types
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: BoardColumnResponse::class)))]
        public array $items,

        #[OA\Property(description: 'The URL of the next page, or null on the last page.', format: 'uri', nullable: true)]
        public ?string $next,

        #[OA\Property(description: 'The card types of the project.', type: 'array', items: new OA\Items(ref: new Model(type: CardTypeResponse::class)))]
        public array $types,

        #[OA\Property(description: 'The type a new card gets.')]
        public string $defaultType,
    ) {
    }
}
