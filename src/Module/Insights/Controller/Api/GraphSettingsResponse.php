<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** The one-metric controls of a saved graph. A formula graph holds its first row here. */
#[OA\Schema(required: ['metric', 'unit', 'statistic', 'group', 'range', 'bucket', 'compare', 'chart'])]
final readonly class GraphSettingsResponse
{
    public function __construct(
        #[OA\Property(description: 'The key of a metric, such as `cost` or `bucket-time:tests`.')]
        public string $metric,

        #[OA\Property(description: 'What one row stands for: `run`, `card` or `reading`.')]
        public string $unit,

        #[OA\Property(description: '`median`, `mean`, `sum`, `p90`, `count` or `max`.')]
        public string $statistic,

        #[OA\Property(description: 'How the graph splits its lines: `stage`, `model`, `variant`, `card-type`, `bridge`, `harness`, `account`, `harness-version`, `cli-version`, `skill-version`, `account-window`, `wake-gap`, `wake-cache` or `none`.')]
        public string $group,

        #[OA\Property(description: '`seven-days`, `thirty-days`, `ninety-days` or `all`.')]
        public string $range,

        #[OA\Property(description: '`day`, `week` or `month`. `seven-days` takes `day` alone.')]
        public string $bucket,

        #[OA\Property(description: '`off`, or `previous` to compare with the period just before the range.')]
        public string $compare,

        #[OA\Property(description: '`line`, `bars`, `stacked-bars`, `percent-stacked`, `donut` or `number`.')]
        public string $chart,
    ) {
    }

    /** @param array<string, string> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(
            $settings['metric'],
            $settings['unit'],
            $settings['statistic'],
            $settings['group'],
            $settings['range'],
            $settings['bucket'],
            $settings['compare'],
            $settings['chart'],
        );
    }
}
