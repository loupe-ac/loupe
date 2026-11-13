<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** A command that a person, an agent or an API key sent to a bridge for a run, as the spec shows it. */
#[OA\Schema(required: ['commandId', 'runId', 'kind', 'state', 'reason', 'requestedAt', 'expiresAt', 'settledAt'])]
final readonly class BridgeCommandResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $commandId,

        #[OA\Property(format: 'uuid')]
        public string $runId,

        #[OA\Property(enum: ['resume-run', 'stop-run', 'rerun-command', 'collect-session-usage', 'try-now'])]
        public string $kind,

        #[OA\Property(enum: ['pending', 'done', 'refused', 'expired', 'cancelled'])]
        public string $state,

        #[OA\Property(description: 'The reason the requester gave, until the bridge settles the command with a reason of its own.', nullable: true)]
        public ?string $reason,

        #[OA\Property(format: 'date-time')]
        public string $requestedAt,

        #[OA\Property(format: 'date-time')]
        public string $expiresAt,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $settledAt,
    ) {
    }
}
