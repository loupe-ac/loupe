<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The board after a reorder. */
#[OA\Schema(required: ['columns'])]
final readonly class ColumnOrderResponse
{
    /** @param list<BoardColumnResponse> $columns */
    public function __construct(
        #[OA\Property(description: 'Every column of the board, in the new order.', type: 'array', items: new OA\Items(ref: new Model(type: BoardColumnResponse::class)))]
        public array $columns,
    ) {
    }
}
