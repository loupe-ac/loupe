<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The column a call read or changed, with the whole board. */
#[OA\Schema(required: ['column', 'columns'])]
final readonly class ColumnWriteResponse
{
    /** @param list<BoardColumnResponse> $columns */
    public function __construct(
        #[OA\Property(description: 'The slug of the column after the change.')]
        public string $column,

        #[OA\Property(description: 'Every column of the board, in board order.', type: 'array', items: new OA\Items(ref: new Model(type: BoardColumnResponse::class)))]
        public array $columns,
    ) {
    }
}
