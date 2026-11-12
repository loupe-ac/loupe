<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** One metric that the metric query reads. */
#[OA\Schema(required: ['key', 'units', 'valueType', 'statistics', 'groups', 'description'])]
final readonly class MetricListEntryResponse
{
    /**
     * @param list<string> $units
     * @param list<string> $statistics
     * @param list<string> $groups
     */
    public function __construct(
        #[OA\Property(description: 'Pass it as `metric` to the metric query.')]
        public string $key,

        #[OA\Property(type: 'array', items: new OA\Items(enum: ['run', 'card', 'reading']))]
        public array $units,

        #[OA\Property(description: 'Money is in US dollars, a duration in milliseconds, except a duration the workflow template declares, which is in hours.', enum: ['money', 'tokens', 'duration', 'count', 'ratio', 'percent'])]
        public string $valueType,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $statistics,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $groups,

        #[OA\Property]
        public string $description,

        #[OA\Property(description: 'The label of a metric that the workflow template declares.', nullable: true)]
        public ?string $label = null,
    ) {
    }
}
