<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** One lettered row of a formula graph. */
#[OA\Schema(required: ['letter', 'metric', 'statistic'])]
final readonly class GraphRowResponse
{
    public function __construct(
        #[OA\Property(description: 'The letter that the formula uses for this row, `A` to `F`.')]
        public string $letter,

        #[OA\Property(description: 'The key of a metric that adds up over runs.')]
        public string $metric,

        #[OA\Property(description: 'The statistic that reads the metric for each bucket and group.')]
        public string $statistic,
    ) {
    }
}
