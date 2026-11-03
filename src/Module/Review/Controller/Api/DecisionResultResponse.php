<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['decisionId', 'url', 'changed', 'cleared'])]
final readonly class DecisionResultResponse
{
    public function __construct(
        #[OA\Property]
        public string $decisionId,

        #[OA\Property(description: 'The review page of the document in the app.', format: 'uri')]
        public string $url,

        #[OA\Property]
        public bool $changed,

        #[OA\Property]
        public bool $cleared,
    ) {
    }
}
