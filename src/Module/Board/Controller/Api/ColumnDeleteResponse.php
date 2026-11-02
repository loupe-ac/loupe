<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** What a column delete removed and moved, with the columns that remain. */
#[OA\Schema(required: ['deleted', 'movedCards', 'targetColumn', 'columns'])]
final readonly class ColumnDeleteResponse
{
    /** @param list<BoardColumnResponse> $columns */
    public function __construct(
        #[OA\Property(description: 'The deleted slug.')]
        public string $deleted,

        #[OA\Property(description: 'The number of moved cards.')]
        public int $movedCards,

        #[OA\Property(description: 'Where the cards went.', nullable: true)]
        public ?string $targetColumn,

        #[OA\Property(description: 'Every column that remains.', type: 'array', items: new OA\Items(ref: new Model(type: BoardColumnResponse::class)))]
        public array $columns,
    ) {
    }
}
