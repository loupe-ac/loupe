<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Insights\Entity\InsightsSavedGraph;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The one-metric controls of a graph. A field left out keeps the value of the graph on a change,
 * and takes its default on a save. The values are checked against the metric and the range, and a
 * value they cannot take is refused with the values that are valid.
 */
#[OA\Schema(description: 'A field you leave out keeps its value on a change, and takes its default on a save.')]
final class GraphSettingsInput
{
    #[Assert\Length(max: InsightsSavedGraph::MAX_METRIC_LENGTH)]
    #[OA\Property(description: 'The key of a metric from the metric list. Leave it out when the graph reads a formula.', nullable: true, maxLength: InsightsSavedGraph::MAX_METRIC_LENGTH)]
    public ?string $metric = null;

    #[OA\Property(description: '`run`, `card` or `reading`. A save needs it.', nullable: true)]
    public ?string $unit = null;

    #[OA\Property(description: '`median`, `mean`, `sum`, `p90`, `count` or `max`. Leave it out when the graph reads a formula.', nullable: true)]
    public ?string $statistic = null;

    #[OA\Property(description: '`stage`, `model`, `variant`, `card-type`, `bridge`, `harness`, `account`, `harness-version`, `cli-version`, `skill-version`, `account-window`, `wake-gap`, `wake-cache` or `none`. A save defaults to `none`.', nullable: true)]
    public ?string $group = null;

    #[OA\Property(description: '`seven-days`, `thirty-days`, `ninety-days` or `all`. A save defaults to `ninety-days`.', nullable: true)]
    public ?string $range = null;

    #[OA\Property(description: '`day`, `week` or `month`. `seven-days` takes `day` alone. A save defaults to `week`.', nullable: true)]
    public ?string $bucket = null;

    #[OA\Property(description: '`off`, or `previous` to compare with the period just before the range. A save defaults to `off`.', nullable: true)]
    public ?string $compare = null;

    #[OA\Property(description: '`line`, `bars`, `stacked-bars`, `percent-stacked`, `donut` or `number`. A save defaults to `line`.', nullable: true)]
    public ?string $chart = null;

    /** @return array<string, string> the controls that the request names, by name */
    public function settings(): array
    {
        return array_filter(
            ['metric' => $this->metric, 'unit' => $this->unit, 'statistic' => $this->statistic, 'group' => $this->group, 'range' => $this->range, 'bucket' => $this->bucket, 'compare' => $this->compare, 'chart' => $this->chart],
            static fn (?string $value): bool => null !== $value,
        );
    }
}
