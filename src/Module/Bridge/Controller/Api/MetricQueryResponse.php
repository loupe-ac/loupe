<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a metric query. */
#[OA\Schema(required: ['unit', 'metric', 'statistic', 'group', 'range', 'bucket', 'compare', 'window', 'series'])]
final readonly class MetricQueryResponse
{
    /**
     * @param array<string, mixed>       $window
     * @param list<array<string, mixed>> $series
     * @param array<string, mixed>|null  $formula
     */
    public function __construct(
        #[OA\Property(enum: ['run', 'card', 'reading'])]
        public string $unit,

        #[OA\Property(description: 'Null for a formula query.', nullable: true)]
        public ?string $metric,

        #[OA\Property(description: 'Null for a formula query.', nullable: true)]
        public ?string $statistic,

        #[OA\Property]
        public string $group,

        #[OA\Property(enum: ['seven-days', 'thirty-days', 'ninety-days', 'all'])]
        public string $range,

        #[OA\Property(enum: ['day', 'week', 'month'])]
        public string $bucket,

        #[OA\Property(enum: ['off', 'previous'])]
        public string $compare,

        #[OA\Property(description: 'The period read (`current`) and the period before it (`previous`, null unless compare is previous). Each has `from` (null for all time) and `to`.', type: 'object')]
        public array $window,

        #[OA\Property(description: 'One series per group. Each has `group`, `total` (`value`, `rows`), `points` (`start`, `value`, `rows`), `rows` (at most 100 rows, newest first: `id`, `cardNumber`, `time`, `value`), `rowsTotal` and, with compare previous, `comparison`.', type: 'array', items: new OA\Items(type: 'object'))]
        public array $series,

        #[OA\Property(description: 'Set for a formula query: `rows` (`letter`, `metric`, `statistic`), `formula`, `format` and `valueType`.', type: 'object', nullable: true)]
        public ?array $formula = null,
    ) {
    }
}
