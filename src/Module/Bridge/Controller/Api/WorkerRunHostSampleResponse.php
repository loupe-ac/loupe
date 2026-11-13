<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** One reading of the machine a bridge runs on, as the spec shows it. */
#[OA\Schema(required: ['sampledAt', 'cpuPct', 'meanCpuPct', 'memUsed', 'memTotal', 'swapUsed', 'batteryPct', 'onAc'])]
final readonly class WorkerRunHostSampleResponse
{
    /** @param list<float> $cpuPct */
    public function __construct(
        #[OA\Property(format: 'date-time')]
        public string $sampledAt,

        #[OA\Property(description: 'The use of each core, in percent.', type: 'array', items: new OA\Items(type: 'number'))]
        public array $cpuPct,

        #[OA\Property(description: 'The mean of cpuPct, rounded to one decimal, or null when cpuPct is empty.', nullable: true)]
        public ?float $meanCpuPct,

        #[OA\Property(description: 'Memory in use, in bytes.')]
        public int $memUsed,

        #[OA\Property(description: 'Memory in total, in bytes.')]
        public int $memTotal,

        #[OA\Property(description: 'Swap in use, in bytes.')]
        public int $swapUsed,

        #[OA\Property(description: 'The battery level in percent, or null on a machine with no battery.', nullable: true)]
        public ?float $batteryPct,

        #[OA\Property(description: 'Whether the machine runs on mains power, or null when it does not say.', nullable: true)]
        public ?bool $onAc,
    ) {
    }
}
