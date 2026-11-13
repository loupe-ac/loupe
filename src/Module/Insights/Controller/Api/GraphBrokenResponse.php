<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** The part of a saved graph that the project no longer has. A broken graph draws no data. */
#[OA\Schema(required: ['reason', 'name'])]
final readonly class GraphBrokenResponse
{
    public function __construct(
        #[OA\Property(description: 'What is gone.', enum: ['template-metric', 'bucket', 'harness-event'])]
        public string $reason,

        #[OA\Property(description: 'The template metric key, the bucket name or the harness event kind.')]
        public string $name,
    ) {
    }
}
