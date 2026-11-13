<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a stop, as the spec shows it. */
#[OA\Schema(required: ['outcome', 'commandId'])]
final readonly class StopWorkerRunResponse
{
    public function __construct(
        #[OA\Property(description: '`stopped` when the run asked its bridge to stop it. `closed` when the run is an interactive session: Loupe marks it closed, and the terminal of the person stays open, because no bridge holds it.', enum: ['stopped', 'closed'])]
        public string $outcome,

        #[OA\Property(description: 'The id of the command that waits for the bridge, or null for a closed session.', format: 'uuid', nullable: true)]
        public ?string $commandId,
    ) {
    }
}
