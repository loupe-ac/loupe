<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
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
 * @phpstan-type MetricQuerySeries array{group: ?string, total: array{value: int|float|null, rows: int}, points: list<MetricQueryPoint>, rows: list<MetricQueryRow>, rowsTotal: int}
 */
#[McpTool(name: self::NAME, description: 'Read one metric of this project over time. metric_list lists each metric with the units, statistics and groups it takes, and a refused combination names the values to use. metric is a key from metric_list, which includes the metrics that the workflow template of the project declares. bucket-time:<name> reads the time of the main-session tool calls of a run in the bucket <name>, a name of 1 to 64 characters of a-z, 0-9, _ and -, and metric_list gives one entry per bucket of the project. A run with no time in a bucket gives 0, and a run with no bucket data has an unknown value. bucket-time-all:<name> reads the time of the tool calls in the bucket with the calls of subagents included, which can exceed the run time, and bucket-calls:<name> reads how many tool calls the run made in the bucket, subagent calls included. Both take the same names, and a run that a recompute has not reached since the release has an unknown value. harness-events:<kind> reads how many harness events of one kind a run held, such as harness-events:diagnostics, a kind being 1 to 64 characters of a-z, 0-9, _, . and -, and metric_list gives one entry per kind of the project. A run that reported events but none of this kind gives 0, and a run that reported none has an unknown value. unit is run, card or reading. A reading row is one stored reading of a usage window of an account, placed at the time it was read, and only account-usage takes the reading unit. account-usage reads the percent of the limit used, as each reading gave it, from the named bridges that follow the project, and takes the group account-window alone, which names the series bridge/account window, as in laptop/work session. session-usage and week-usage read the change in that percent over a run, from its first to its last reading inside the run, in percentage points, and take the run unit alone. A run row is one closed run, placed at its end, or at the time its first report arrived when it has no end. A card row is one finished card, placed at the time it was completed. A card whose runs used two or more variants gives one row per variant. Each row carries the whole card outcome, so the card counts once per variant. The metrics start with the oldest run that the server held at the upgrade, so a run deleted before then is not counted. statistic is median, mean, sum, p90, count or max, and a metric offers the ones that fit its value. group splits the rows into one series per stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version or account-window, and none gives one series. range is thirty-days, ninety-days or all. bucket is day, week or month, and a bucket starts at UTC midnight, on the ISO Monday or on the first of the month. A money value is in US dollars. A duration is in milliseconds, except a duration that the workflow template declares, which is in hours. A ratio is between 0 and 1. A percent is a number of percent, such as 96 for 96 percent, and it can pass 100. A row whose value is unknown has a null value and stays out of every statistic. Each series has group, total, points, rows and rowsTotal. group is null for the rows with no group. total has value, the statistic over all the rows, and rows, the number of rows with a known value. points has one entry per bucket with rows, oldest first, each with start, value and rows. rows lists at most 100 rows, newest first, each with id, cardNumber, time and value. id is the run id or the card id. rowsTotal is the number of rows before the cut. The series with no group comes last.')]
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
     * @param string $unit      what one row stands for: run, card or reading
     * @param string $metric    the key of a metric from metric_list, such as cost, duration, stop-rate, account-usage, session-usage, week-usage, bucket-time:<name>, bucket-time-all:<name>, bucket-calls:<name> or harness-events:<kind>
     * @param string $statistic median, mean, sum, p90, count or max
     * @param string $group     stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window or none
     * @param string $range     thirty-days, ninety-days or all
     * @param string $bucket    day, week or month
     *
     * @return array{unit: string, metric: string, statistic: string, group: string, range: string, bucket: string, series: list<MetricQuerySeries>}
     */
    public function __invoke(string $unit, string $metric, string $statistic, string $group = 'none', string $range = 'thirty-days', string $bucket = 'week'): array
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
                'series' => array_map(self::series(...), $view->series),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The metric could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return MetricQuerySeries */
    private static function series(MetricSeries $series): array
    {
        return [
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
