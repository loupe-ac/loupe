<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** The runs an analysis reads. */
#[OA\Schema(required: ['range'])]
final readonly class AnalysisScopeResponse
{
    public function __construct(
        #[OA\Property(enum: ['thirty-days', 'ninety-days', 'all'])]
        public string $range,

        #[OA\Property(description: 'The experiment an experiment analysis compares.', nullable: true)]
        public ?string $experiment = null,
    ) {
    }
}
