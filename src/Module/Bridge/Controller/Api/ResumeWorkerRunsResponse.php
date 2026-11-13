<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a batch resume: one row per run, in the order of the request. */
#[OA\Schema(required: ['results'])]
final readonly class ResumeWorkerRunsResponse
{
    /** @param list<array<string, mixed>> $results */
    public function __construct(
        #[OA\Property(description: 'One row per run id. `resumed` carries `commandId`. `refused` carries `code` and `message`. The codes are not-found (no run of this project has this id), no-bridge, unknown-bridge, bridge-outdated, pending, not-controllable, no-session, not-resumable and card-held. `error` says that an unexpected failure hit that run and its outcome is unknown. The route then stops, and each later row is `not-attempted`.', type: 'array', items: new OA\Items(required: ['runId', 'outcome'], properties: [
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'outcome', type: 'string', enum: ['resumed', 'refused', 'error', 'not-attempted']),
            new OA\Property(property: 'commandId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'message', type: 'string'),
        ], type: 'object'))]
        public array $results,
    ) {
    }
}
