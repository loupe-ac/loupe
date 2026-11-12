<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** One experiment of the list, as the spec shows it. ExperimentReportPayload builds the real body. */
#[OA\Schema(required: ['name', 'cards', 'lastRunAt', 'archived'])]
final readonly class ExperimentSummaryRowResponse
{
    public function __construct(
        #[OA\Property(description: 'The name of the experiment, as the runs name it.')]
        public string $name,

        #[OA\Property(description: 'How many cards the runs and the pins of the experiment name.', minimum: 0)]
        public int $cards,

        #[OA\Property(description: 'The time of the last run of the experiment, or null when only pins name it.', format: 'date-time', nullable: true)]
        public ?string $lastRunAt,

        #[OA\Property(description: 'True when the owner archived the experiment and no run has brought it back.')]
        public bool $archived,
    ) {
    }
}
