<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a discovery start. */
#[OA\Schema(required: ['runId', 'cardId', 'cardNumber', 'state', 'url'])]
final readonly class DiscoveryStartResponse
{
    public function __construct(
        #[OA\Property(description: 'The id of the discovery run.', format: 'uuid')]
        public string $runId,

        #[OA\Property(description: 'The id of the discovery card.', format: 'uuid')]
        public string $cardId,

        #[OA\Property(description: 'The number of the discovery card.', minimum: 1)]
        public int $cardNumber,

        #[OA\Property(description: 'The state of the run. A new run is `requested`.', enum: ['requested'])]
        public string $state,

        #[OA\Property(description: 'The card page in the app. The report is linked there once the worker has written it.', format: 'uri')]
        public string $url,
    ) {
    }
}
