<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One run with its series and its commands, as the spec shows it. */
#[OA\Schema(required: ['run', 'series', 'commands'])]
final readonly class WorkerRunShowResponse
{
    /**
     * @param list<WorkerRunResponse>     $series
     * @param list<BridgeCommandResponse> $commands
     */
    public function __construct(
        #[OA\Property(ref: new Model(type: WorkerRunDetailResponse::class))]
        public WorkerRunDetailResponse $run,

        #[OA\Property(description: 'Every run of the series of the run, oldest first, the run included. A series starts with one run, and each resume starts a new run that continues it.', type: 'array', items: new OA\Items(ref: new Model(type: WorkerRunResponse::class)))]
        public array $series,

        #[OA\Property(description: 'Every command that a person, an agent or a key sent to the bridge for a run of the series, oldest first.', type: 'array', items: new OA\Items(ref: new Model(type: BridgeCommandResponse::class)))]
        public array $commands,
    ) {
    }
}
