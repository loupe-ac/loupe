<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

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
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Finds each setting that the Metrics page would swap for another value. The page and the saved graph handlers
 * fall back silently. A tool or a route must not save a graph that the caller did not ask for, so this compares
 * each setting with the value that MetricsQuery keeps, and names the values that are valid.
 */
final readonly class SavedGraphSettingsProblems
{
    public function __construct(
        private TemplateMetrics $templateMetrics,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $settings the settings to check, by name
     *
     * @return list<array{field: string, message: string}> one entry for each setting that the metric or the range cannot take
     */
    public function find(Project $project, array $settings): array
    {
        $templates = $this->templateMetrics->forProject($project->requireId());
        /** @var InputBag<string> $posted */
        $posted = new InputBag($settings);
        $query = MetricsQuery::fromQuery($posted, $templates);
        $lines = [];

        $formula = $query->formula;
        if (null !== $formula?->problem) {
            $lines[] = ['field' => 'formula', 'message' => $formula->problem->english($this->translator)];
        } elseif (null === $formula && '' !== ($settings['format'] ?? '')) {
            $lines[] = ['field' => 'format', 'message' => 'A format needs rows and a formula.'];
        } elseif (null === $formula && isset($settings['metric']) && $query->metric->key->key() !== $settings['metric']) {
            $lines[] = ['field' => 'metric', 'message' => \sprintf('Unknown metric "%s", or a template metric that the project no longer declares. metric_list lists the metrics.', $settings['metric'])];
        }
        if ([] === $lines) {
            $lines = $this->enumLines($settings, $query);
        }
        if (isset($settings['chart']) && $query->chart->value !== $settings['chart']) {
            $lines[] = ['field' => 'chart', 'message' => \sprintf('Unknown chart "%s". Use one of: %s.', $settings['chart'], self::values(MetricChartType::cases()))];
        }

        return $lines;
    }

    /**
     * @param array<string, string> $settings
     *
     * @return list<array{field: string, message: string}>
     */
    private function enumLines(array $settings, MetricsQuery $query): array
    {
        $metric = $query->metric;
        $formula = $query->formula;
        $lines = [];
        $rangeOk = !isset($settings['range']) || $query->range->value === $settings['range'];
        $fields = [
            'unit' => [$query->unit, MetricUnit::cases(), $formula?->units() ?? $metric->units()],
            'statistic' => [$query->statistic, MetricStatistic::cases(), $metric->statistics()],
            'group' => [$query->group, MetricGroup::cases(), $formula?->groups() ?? $metric->groups()],
            'range' => [$query->range, MetricRange::cases(), MetricRange::cases()],
            'bucket' => [$query->bucket, MetricBucket::cases(), $query->range->allowedBuckets()],
        ];
        foreach ($fields as $field => [$kept, $all, $allowed]) {
            // The statistic of a formula graph belongs to each row, and the rows were checked with the formula.
            if (!isset($settings[$field]) || $kept->value === $settings[$field] || (null !== $formula && 'statistic' === $field)) {
                continue;
            }
            if (null === $this->tryCase($all, $settings[$field])) {
                $lines[] = ['field' => $field, 'message' => \sprintf('Unknown %s "%s". Use one of: %s.', $field, $settings[$field], self::values($all))];
            } elseif ('bucket' === $field) {
                $lines[] = ['field' => 'bucket', 'message' => \sprintf('The range %s does not take the bucket "%s". Use one of: %s.', $query->range->value, $settings['bucket'], self::values($allowed))];
            } elseif ($rangeOk || 'range' === $field) {
                $lines[] = ['field' => $field, 'message' => null === $formula
                    ? \sprintf('The metric %s does not take the %s "%s". Use one of: %s.', $metric->key->key(), $field, $settings[$field], self::values($allowed))
                    : \sprintf('The rows of this formula do not all take the %s "%s". Use one of: %s.', $field, $settings[$field], self::values($allowed))];
            }
        }
        if (isset($settings['compare']) && $query->compare->value !== $settings['compare']) {
            $lines[] = ['field' => 'compare', 'message' => null === $this->tryCase(MetricCompare::cases(), $settings['compare'])
                ? \sprintf('Unknown compare "%s". Use one of: %s.', $settings['compare'], self::values(MetricCompare::cases()))
                : \sprintf('The range %s has no previous period to compare with. Use off, or another range.', $query->range->value)];
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
