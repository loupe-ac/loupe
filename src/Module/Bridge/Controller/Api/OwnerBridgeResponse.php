<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** A bridge of the owner, as the spec shows it. BridgeApiPayload builds the real body. */
#[OA\Schema(required: ['bridgeId', 'name', 'requestedName', 'liveness', 'quietCause', 'lastSeenAt', 'cliVersion', 'pauseRequested', 'pausedReported', 'takesCommands', 'takesReruns', 'projectIds'])]
final readonly class OwnerBridgeResponse
{
    /** @param list<string> $projectIds */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $bridgeId,

        #[OA\Property(description: 'The name the bridge holds, or null.', nullable: true)]
        public ?string $name,

        #[OA\Property(description: 'The name its last heartbeat asked for, or null.', nullable: true)]
        public ?string $requestedName,

        #[OA\Property(enum: ['live', 'quiet'])]
        public string $liveness,

        #[OA\Property(nullable: true, enum: ['login-expired', 'unknown'])]
        public ?string $quietCause,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $lastSeenAt,

        #[OA\Property(description: 'The CLI version the bridge reported.')]
        public string $cliVersion,

        #[OA\Property(description: 'True when a person asked the bridge to start no new work.')]
        public bool $pauseRequested,

        #[OA\Property(nullable: true)]
        public ?bool $pausedReported,

        #[OA\Property(description: 'False for an older bridge, which cannot resume or stop a run.')]
        public bool $takesCommands,

        #[OA\Property(description: 'False for a bridge that cannot run a failed command run again.')]
        public bool $takesReruns,

        #[OA\Property(description: 'The ids of the projects that the bridge serves. A credential bound to one project sees that project alone.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
        public array $projectIds,
    ) {
    }
}
