<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use OpenApi\Attributes as OA;

/** The latest discovery run of a project. */
#[OA\Schema(required: ['runId', 'state', 'reason', 'createdAt'])]
final readonly class ReadinessDiscoveryResponse
{
    public function __construct(
        #[OA\Property(description: 'The id of the discovery run. `readiness_report_submit` takes it.', format: 'uuid')]
        public string $runId,

        #[OA\Property(description: 'The state of the run.')]
        public string $state,

        #[OA\Property(description: 'Why the run failed. Null when it did not fail.', nullable: true)]
        public ?string $reason,

        #[OA\Property(description: 'When the run was requested.', format: 'date-time')]
        public string $createdAt,
    ) {
    }
}
