<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Refuses a setting that the Metrics page would swap for another value. The page and the saved graph handlers
 * fall back silently. A tool must not save a graph that the agent did not ask for, so it compares each setting
 * with the value that MetricsQuery keeps, and names the values that are valid.
 */
final readonly class SavedGraphSettingsCheck
{
    public function __construct(
        private TemplateMetrics $templateMetrics,
    ) {
    }

    /**
     * @param array<string, string> $settings the settings to check, by name
     *
     * @throws ToolCallException listing each setting that the metric or the range cannot take
     */
    public function check(Project $project, array $settings): void
    {
        $templates = $this->templateMetrics->forProject($project->requireId());
        /** @var InputBag<string> $posted */
        $posted = new InputBag($settings);
        $query = MetricsQuery::fromQuery($posted, $templates);
        $lines = [];

        if (isset($settings['metric']) && $query->metric->key->key() !== $settings['metric']) {
            $lines[] = \sprintf('metric: Unknown metric "%s", or a template metric that the project no longer declares. metric_list lists the metrics.', $settings['metric']);
        }
        if ([] === $lines) {
            $lines = $this->enumLines($settings, $query);
        }
        if (isset($settings['chart']) && $query->chart->value !== $settings['chart']) {
            $lines[] = \sprintf('chart: Unknown chart "%s". Use one of: %s.', $settings['chart'], self::values(MetricChartType::cases()));
        }

        if ([] !== $lines) {
            throw new ToolCallException(implode("\n", $lines));
        }
    }

    /**
     * @param array<string, string> $settings
     *
     * @return list<string>
     */
    private function enumLines(array $settings, MetricsQuery $query): array
    {
        $metric = $query->metric;
        $lines = [];
        $rangeOk = !isset($settings['range']) || $query->range->value === $settings['range'];
        $fields = [
            'unit' => [$query->unit, MetricUnit::cases(), $metric->units()],
            'statistic' => [$query->statistic, MetricStatistic::cases(), $metric->statistics()],
            'group' => [$query->group, MetricGroup::cases(), $metric->groups()],
            'range' => [$query->range, MetricRange::cases(), MetricRange::cases()],
            'bucket' => [$query->bucket, MetricBucket::cases(), $query->range->allowedBuckets()],
        ];
        foreach ($fields as $field => [$kept, $all, $allowed]) {
            if (!isset($settings[$field]) || $kept->value === $settings[$field]) {
                continue;
            }
            if (null === $this->tryCase($all, $settings[$field])) {
                $lines[] = \sprintf('%s: Unknown %s "%s". Use one of: %s.', $field, $field, $settings[$field], self::values($all));
            } elseif ('bucket' === $field) {
                $lines[] = \sprintf('bucket: The range %s does not take the bucket "%s". Use one of: %s.', $query->range->value, $settings['bucket'], self::values($allowed));
            } elseif ($rangeOk || 'range' === $field) {
                $lines[] = \sprintf('%s: The metric %s does not take the %s "%s". Use one of: %s.', $field, $metric->key->key(), $field, $settings[$field], self::values($allowed));
            }
        }
        if (isset($settings['compare']) && $query->compare->value !== $settings['compare']) {
            $lines[] = null === $this->tryCase(MetricCompare::cases(), $settings['compare'])
                ? \sprintf('compare: Unknown compare "%s". Use one of: %s.', $settings['compare'], self::values(MetricCompare::cases()))
                : \sprintf('compare: The range %s has no previous period to compare with. Use off, or another range.', $query->range->value);
        }

        return $lines;
    }

    /**
     * @param list<\BackedEnum> $cases
     */
    private function tryCase(array $cases, string $value): ?\BackedEnum
    {
        return array_find($cases, static fn (\BackedEnum $case): bool => (string) $case->value === $value);
    }

    /** @param list<\BackedEnum> $cases */
    private static function values(array $cases): string
    {
        return implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
