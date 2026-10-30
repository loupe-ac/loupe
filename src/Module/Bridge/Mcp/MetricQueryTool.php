<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads one metric of the project's worker runs or finished cards over time.
 *
 * @phpstan-type MetricQueryPoint array{start: string, value: int|float|null, rows: int}
 * @phpstan-type MetricQueryRow array{id: string, cardNumber: ?int, time: string, value: int|float|null}
 * @phpstan-type MetricQueryWindow array{from: ?string, to: string}
 * @phpstan-type MetricQueryRange array{low: float, high: float}
 * @phpstan-type MetricQueryComparison array{previous: array{value: int|float|null, rows: int}, change: ?array{value: float, kind: string}, likelyRange: ?array{current: ?MetricQueryRange, previous: ?MetricQueryRange, change: ?MetricQueryRange}, sign: ?string}
 * @phpstan-type MetricQuerySeries array{group: ?string, total: array{value: int|float|null, rows: int}, points: list<MetricQueryPoint>, rows: list<MetricQueryRow>, rowsTotal: int, comparison?: ?MetricQueryComparison}
 */
#[McpTool(name: self::NAME, description: 'Read one metric of this project over time. metric_list lists each metric with the units, statistics and groups it takes, and a refused combination names the values to use. metric is a key from metric_list, which includes the metrics that the workflow template of the project declares. bucket-time:<name> reads the time of the main-session tool calls of a run in the bucket <name>, a name of 1 to 64 characters of a-z, 0-9, _ and -, and metric_list gives one entry per bucket of the project. A run with no time in a bucket gives 0, and a run with no bucket data has an unknown value. bucket-time-all:<name> reads the time of the tool calls in the bucket with the calls of subagents included, which can exceed the run time, and bucket-calls:<name> reads how many tool calls the run made in the bucket, subagent calls included. Both take the same names, and a run that a recompute has not reached since the release has an unknown value. harness-events:<kind> reads how many harness events of one kind a run held, such as harness-events:diagnostics, a kind being 1 to 64 characters of a-z, 0-9, _, . and -, and metric_list gives one entry per kind of the project. A run that reported events but none of this kind gives 0, and a run that reported none has an unknown value. unit is run or card. A run row is one closed run, placed at its end, or at the time its first report arrived when it has no end. A card row is one finished card, placed at the time it was completed. A card whose runs used two or more variants gives one row per variant. Each row carries the whole card outcome, so the card counts once per variant. The metrics start with the oldest run that the server held at the upgrade, so a run deleted before then is not counted. statistic is median, mean, sum, p90 or count. group splits the rows into one series per stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version or skill-version, and none gives one series. range is seven-days, thirty-days, ninety-days or all, and the default is thirty-days. bucket is day, week or month, and a bucket starts at UTC midnight, on the ISO Monday or on the first of the month. seven-days takes the bucket day alone. bucket-time-each, bucket-time-all-each and bucket-calls-each take no name and group none: each bucket of the project rules is one series, and a bucket that a run did not touch counts 0, so the means of the series add up to the mean of the whole. A money value is in US dollars. A duration is in milliseconds, except a duration that the workflow template declares, which is in hours. A ratio is between 0 and 1. A row whose value is unknown has a null value and stays out of every statistic. Each series has group, total, points, rows and rowsTotal. group is null for the rows with no group. total has value, the statistic over all the rows, and rows, the number of rows with a known value. points has one entry per bucket with rows, oldest first, each with start, value and rows. rows lists at most 100 rows, newest first, each with id, cardNumber, time and value. id is the run id or the card id. rowsTotal is the number of rows before the cut. The series with no group comes last. compare is off or previous, and the default is off. With previous, the tool reads the period of the same length just before the range, and the output gains window, with the start and the end of each period. Each series then gains comparison, which holds previous, the statistic over the previous period with value and rows, and change, with value and kind. The kind is relative, a fraction of the previous value, points for the difference of two rates, or absolute in the unit of the metric when the previous value is zero. change is null when a period has no known value. comparison also holds likelyRange with the current, previous and change ranges, each with low and high, and sign: clear when the likely ranges of the two periods do not overlap, not-clear-yet when they overlap, and not-enough-data when a period has fewer than 5 distinct cards with a known value. The sign and the likely ranges are null for the sum and the count statistics. The ranges widen with the number of series, so a graph with many series shows a clear sign more rarely. compare previous refuses the range all.')]
final readonly class MetricQueryTool
{
    public const string NAME = 'metric_query';

    public const int MAX_ROWS = 100;

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private MetricQueryHandler $query,
        private TemplateMetrics $templateMetrics,
    ) {
    }

    /**
     * @param string $unit      what one row stands for: run or card
     * @param string $metric    the key of a metric from metric_list, such as cost, duration, stop-rate, bucket-time:<name>, bucket-time-all:<name>, bucket-calls:<name> or harness-events:<kind>
     * @param string $statistic median, mean, sum, p90 or count
     * @param string $group     stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version or none
     * @param string $range     seven-days, thirty-days, ninety-days or all
     * @param string $bucket    day, week or month; seven-days takes day alone
     * @param string $compare   off, or previous to read the period of the same length just before the range
     *
     * @return array{unit: string, metric: string, statistic: string, group: string, range: string, bucket: string, compare: string, window: array{current: MetricQueryWindow, previous: ?MetricQueryWindow}, series: list<MetricQuerySeries>}
     */
    public function __invoke(string $unit, string $metric, string $statistic, string $group = 'none', string $range = 'thirty-days', string $bucket = 'week', string $compare = 'off'): array
    {
        try {
            $project = $this->subjects->requireReadableProject();
            $shape = self::parseMetric($metric, $this->templateMetrics->forProject($project->requireId()));
            $command = new MetricQueryCommand(
                $project,
                self::parse(MetricUnit::class, 'unit', $unit),
                $shape,
                self::parse(MetricStatistic::class, 'statistic', $statistic),
                self::parse(MetricGroup::class, 'group', $group),
                self::parse(MetricRange::class, 'range', $range),
                self::parse(MetricBucket::class, 'bucket', $bucket),
                self::parse(MetricCompare::class, 'compare', $compare),
            );

            try {
                $view = ($this->query)($command);
            } catch (DomainErrors $e) {
                throw new ToolCallException(self::refusal($command, $e));
            }

            return [
                'unit' => $command->unit->value,
                'metric' => $shape->key->key(),
                'statistic' => $command->statistic->value,
                'group' => $command->group->value,
                'range' => $command->range->value,
                'bucket' => $command->bucket->value,
                'compare' => $command->compare->value,
                'window' => ['current' => self::window($view->current), 'previous' => null === $view->previous ? null : self::window($view->previous)],
                'series' => array_map(self::series(...), $view->series),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The metric could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return MetricQueryWindow */
    private static function window(MetricWindow $window): array
    {
        return ['from' => $window->from?->format(\DATE_ATOM), 'to' => $window->to->format(\DATE_ATOM)];
    }

    /** @return ?MetricQueryComparison */
    private static function comparison(?MetricComparison $comparison): ?array
    {
        if (null === $comparison) {
            return null;
        }
        $range = static fn (?Interval $interval): ?array => null === $interval ? null : ['low' => $interval->low, 'high' => $interval->high];

        return [
            'previous' => ['value' => $comparison->previous->value, 'rows' => $comparison->previous->rows],
            'change' => null === $comparison->change || null === $comparison->changeKind ? null : ['value' => $comparison->change, 'kind' => $comparison->changeKind->value],
            'likelyRange' => null === $comparison->sign ? null : [
                'current' => $range($comparison->currentRange),
                'previous' => $range($comparison->previousRange),
                'change' => $range($comparison->changeRange),
            ],
            'sign' => $comparison->sign?->value,
        ];
    }

    /** @return MetricQuerySeries */
    private static function series(MetricSeries $series): array
    {
        $output = [
            'group' => $series->group,
            'total' => ['value' => $series->total->value, 'rows' => $series->total->rows],
            'points' => array_map(static fn (MetricPoint $point): array => [
                'start' => ($point->start ?? throw new \LogicException('A bucket has a start.'))->format(\DATE_ATOM),
                'value' => $point->value,
                'rows' => $point->rows,
            ], $series->points),
            'rows' => array_map(static fn (MetricRow $row): array => [
                'id' => $row->unitId->toRfc4122(),
                'cardNumber' => $row->cardNumber,
                'time' => $row->time->format(\DATE_ATOM),
                'value' => $row->value,
            ], \array_slice($series->rows, 0, self::MAX_ROWS)),
            'rowsTotal' => \count($series->rows),
        ];

        return null === $series->comparison ? $output : $output + ['comparison' => self::comparison($series->comparison)];
    }

    private static function parseMetric(string $value, TemplateMetricSet $templates): MetricShape
    {
        $key = MetricKey::tryParse($value, $templates);
        $shape = null === $key ? null : MetricShape::of($key, $templates);
        if (null !== $shape) {
            return $shape;
        }

        $events = Metric::HarnessEvents->value;
        $families = [Metric::BucketTime->value => 'a bucket', Metric::BucketTimeAll->value => 'a bucket', Metric::BucketCalls->value => 'a bucket', $events => 'a harness event kind'];
        foreach ($families as $family => $what) {
            if ($value === $family || str_starts_with($value, $family.':')) {
                throw new ToolCallException(\sprintf('The metric %s needs the name of %s, as in %s:<name>. A name is 1 to 64 characters of a-z, 0-9, "_" and "-"%s. metric_list lists the names of the project.', $family, $what, $family, $family === $events ? ' and "."' : ''));
            }
        }

        throw new ToolCallException(\sprintf('Unknown metric "%s". Use one of: %s, %s:<name>, %s:<name>, %s:<name>, %s:<kind>.', $value, implode(', ', [...array_map(static fn (Metric $metric): string => $metric->value, Metric::standalone()), ...array_map(static fn (TemplateMetric $metric): string => $metric->key, $templates->metrics)]), Metric::BucketTime->value, Metric::BucketTimeAll->value, Metric::BucketCalls->value, $events));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function parse(string $enum, string $field, string $value): \BackedEnum
    {
        return $enum::tryFrom($value)
            ?? throw new ToolCallException(\sprintf('Unknown %s "%s". Use one of: %s.', $field, $value, self::valuesOf($enum::cases())));
    }

    private static function refusal(MetricQueryCommand $command, DomainErrors $errors): string
    {
        $metric = $command->metric;
        $parts = [];
        foreach (array_keys($errors->errors) as $field) {
            if ('bucket' === $field) {
                $parts[] = \sprintf('The range %s does not take the bucket "%s". Use one of: %s.', $command->range->value, $command->bucket->value, self::valuesOf($command->range->allowedBuckets()));
                continue;
            }
            if ('compare' === $field) {
                $parts[] = \sprintf('The range %s has no previous period to compare with. Use compare off, or another range.', $command->range->value);
                continue;
            }
            [$given, $allowed] = match ($field) {
                'unit' => [$command->unit, $metric->units()],
                'statistic' => [$command->statistic, $metric->statistics()],
                'group' => [$command->group, $metric->groups()],
                default => throw new \LogicException(\sprintf('The metric query refuses no field "%s".', $field)),
            };
            $parts[] = \sprintf('The metric %s does not take the %s "%s". Use one of: %s.', $metric->key->key(), $field, $given->value, self::valuesOf($allowed));
        }
        $parts[] = 'metric_list lists the valid combinations.';

        return implode(' ', $parts);
    }

    /** @param list<\BackedEnum> $cases */
    private static function valuesOf(array $cases): string
    {
        return implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
