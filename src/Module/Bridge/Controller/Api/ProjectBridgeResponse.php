<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** A bridge that serves one project, as the spec shows it. BridgeApiPayload builds the real body. */
#[OA\Schema(required: ['bridgeId', 'name', 'requestedName', 'gitIdentity', 'liveness', 'quietCause', 'lastSeenAt', 'cliVersion', 'pauseRequested', 'pausedReported', 'takesCommands', 'takesReruns', 'acceptsEntries', 'commandEntries', 'workerPools', 'workerPoolsReportedAt', 'accounts', 'accountsReportedAt', 'skills', 'openRuns', 'refusedRequests'])]
final readonly class ProjectBridgeResponse
{
    /**
     * @param array<string, mixed>|null       $gitIdentity
     * @param list<array<string, mixed>>|null $commandEntries
     * @param list<array<string, mixed>>|null $workerPools
     * @param list<array<string, mixed>>|null $accounts
     * @param list<array<string, mixed>>|null $skills
     * @param list<array<string, mixed>>      $openRuns
     * @param list<array<string, mixed>>      $refusedRequests
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $bridgeId,

        #[OA\Property(description: 'The name the bridge holds, or null.', nullable: true)]
        public ?string $name,

        #[OA\Property(description: 'The name its last heartbeat asked for, or null. A requestedName with a null name means another bridge of the owner already holds that name.', nullable: true)]
        public ?string $requestedName,

        #[OA\Property(description: 'The git identity of the bridge for this project, or null when it reported none. `pushLogin` is the GitHub login the pushes use, or empty when it is unknown.', required: ['mode', 'commitName', 'pushLogin', 'githubUserId'], properties: [
            new OA\Property(property: 'mode', type: 'string', enum: ['agent_account', 'app_token', 'machine']),
            new OA\Property(property: 'commitName', type: 'string'),
            new OA\Property(property: 'pushLogin', type: 'string'),
            new OA\Property(property: 'githubUserId', type: 'integer', nullable: true),
        ], type: 'object', nullable: true)]
        public ?array $gitIdentity,

        #[OA\Property(description: 'Live while the bridge sends its heartbeat, and quiet when it missed several.', enum: ['live', 'quiet'])]
        public string $liveness,

        #[OA\Property(description: 'Null for a live bridge. login-expired when a quiet bridge lost the login that can no longer refresh, and unknown for any other quiet bridge.', nullable: true, enum: ['login-expired', 'unknown'])]
        public ?string $quietCause,

        #[OA\Property(description: 'The time of its last heartbeat.', format: 'date-time', nullable: true)]
        public ?string $lastSeenAt,

        #[OA\Property(description: 'The CLI version the bridge reported.')]
        public string $cliVersion,

        #[OA\Property(description: 'True when a person asked the bridge to start no new work.')]
        public bool $pauseRequested,

        #[OA\Property(description: 'Whether the bridge reported that it is paused, or null when it reported nothing.', nullable: true)]
        public ?bool $pausedReported,

        #[OA\Property(description: 'False for an older bridge, which cannot resume or stop a run.')]
        public bool $takesCommands,

        #[OA\Property(description: 'False for a bridge that cannot run a failed command run again.')]
        public bool $takesReruns,

        #[OA\Property(description: 'True when the bridge adds a proposed command entry to its rule file after the owner confirms.')]
        public bool $acceptsEntries,

        #[OA\Property(description: 'Each command entry the bridge can run, as its last heartbeat reported them: `name`, `description`, `params`, `run` and `timeout`. Null when it reported none.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), nullable: true)]
        public ?array $commandEntries,

        #[OA\Property(description: 'Each worker pool of the bridge, with `name`, `size`, `inUse` and `queued`, as its last heartbeat reported them. Null when it reported none.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), nullable: true)]
        public ?array $workerPools,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $workerPoolsReportedAt,

        #[OA\Property(description: 'Each account the bridge checked, with `name`, `harness`, `state`, `reason`, `used`, `projectFailure` and `otherFailedProjects`. `projectFailure` is the reason the account fails for this project, and `otherFailedProjects` counts the other projects it fails for, without naming them. Null when the bridge reported none.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), nullable: true)]
        public ?array $accounts,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $accountsReportedAt,

        #[OA\Property(description: 'Each skill package the bridge installed, with `package`, `version`, `state` and `reason`. Null when the bridge reported none.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), nullable: true)]
        public ?array $skills,

        #[OA\Property(description: 'The queued, resumed, preparing, running and stopping runs of this project on the bridge.', type: 'array', items: new OA\Items(required: ['runId', 'subjectType', 'subjectId', 'cardNumber', 'workKind', 'state'], properties: [
            new OA\Property(property: 'runId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'subjectType', type: 'string'),
            new OA\Property(property: 'subjectId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'cardNumber', type: 'integer', nullable: true),
            new OA\Property(property: 'workKind', type: 'string', nullable: true),
            new OA\Property(property: 'state', type: 'string'),
        ], type: 'object'))]
        public array $openRuns,

        #[OA\Property(description: 'The open work requests of this project that the server refused to the bridge. The server refuses a claim when the account it names, or the lack of one, does not match the account that runs the work kind.', type: 'array', items: new OA\Items(required: ['workRequestId', 'subjectType', 'subjectId', 'kind', 'account'], properties: [
            new OA\Property(property: 'workRequestId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'subjectType', type: 'string'),
            new OA\Property(property: 'subjectId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'kind', type: 'string'),
            new OA\Property(property: 'account', type: 'string', nullable: true),
        ], type: 'object'))]
        public array $refusedRequests,
    ) {
    }
}
