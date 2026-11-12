<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One worker run in full, as the spec shows it. WorkerRunApiPayload builds the real body. */
#[OA\Schema(required: ['continuesRunId', 'receivedAt', 'failureReason', 'output', 'stateChanges', 'buckets', 'harnessEvents'], allOf: [new OA\Schema(ref: new Model(type: WorkerRunResponse::class))])]
final readonly class WorkerRunDetailResponse
{
    /**
     * @param list<array<string, mixed>>      $stateChanges
     * @param list<array<string, mixed>>|null $buckets
     * @param array<string, int>|null         $harnessEvents
     */
    public function __construct(
        #[OA\Property(description: 'The run that this run resumes, or null.', format: 'uuid', nullable: true)]
        public ?string $continuesRunId,

        #[OA\Property(description: 'When the first report of the run arrived.', format: 'date-time')]
        public string $receivedAt,

        #[OA\Property(description: 'The failure reason the bridge gave, or null.', nullable: true)]
        public ?string $failureReason,

        #[OA\Property(description: 'The whole output the bridge reported.')]
        public string $output,

        #[OA\Property(description: 'Each state the run reached, oldest first.', type: 'array', items: new OA\Items(required: ['state', 'at'], properties: [
            new OA\Property(property: 'state', type: 'string'),
            new OA\Property(property: 'at', type: 'string', format: 'date-time'),
        ], type: 'object'))]
        public array $stateChanges,

        #[OA\Property(description: 'The time buckets of the run, largest first, or null for a run with no tool calls. `mainMs` is the time of the main session, `withSubagentsMs` the time of every session, and `calls` every call, subagents included. A figure is null on a row that no recompute has filled.', type: 'array', items: new OA\Items(required: ['bucket', 'mainMs', 'withSubagentsMs', 'calls'], properties: [
            new OA\Property(property: 'bucket', type: 'string'),
            new OA\Property(property: 'mainMs', type: 'integer'),
            new OA\Property(property: 'withSubagentsMs', type: 'integer', nullable: true),
            new OA\Property(property: 'calls', type: 'integer', nullable: true),
        ], type: 'object'), nullable: true)]
        public ?array $buckets,

        #[OA\Property(description: 'Each kind of harness event with its count, subagents included, or null when the bridge reported none.', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'integer'))]
        public ?array $harnessEvents,
    ) {
    }
}
