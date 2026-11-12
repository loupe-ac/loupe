<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a graph removal. */
#[OA\Schema(required: ['undoUntil'])]
final readonly class GraphRemovedResponse
{
    public function __construct(
        #[OA\Property(description: 'The time until which `POST /graphs/{graph}/restore` brings the graph back.', format: 'date-time')]
        public string $undoUntil,
    ) {
    }
}
