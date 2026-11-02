<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['decisionId', 'changed', 'cleared'])]
final readonly class DecisionResultResponse
{
    public function __construct(
        #[OA\Property]
        public string $decisionId,

        #[OA\Property]
        public bool $changed,

        #[OA\Property]
        public bool $cleared,
    ) {
    }
}
