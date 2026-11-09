<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Formula\FormulaRow;
use App\Module\Bridge\Metric\Formula\MetricFormula;
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
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads one metric of the project's worker runs or finished cards over time.
 *
 * @phpstan-type MetricQueryPoint array{start: string, value: int|float|null, rows: int}
 * @phpstan-type MetricQueryRow array{id: string, cardNumber: ?int, time: string, value: int|float|null}
 * @phpstan-type MetricQueryWindow array{from: ?string, to: string}
 * @phpstan-type MetricQueryRange array{low: float, high: float}
 * @phpstan-type MetricQueryComparison array{previous: array{value: int|float|null, rows: int}, change: ?array{value: float, kind: string}, likelyRange: ?array{current: ?MetricQueryRange, previous: ?MetricQueryRange, change: ?MetricQueryRange}, sign: ?string}
 * @phpstan-type MetricQueryFormula array{rows: list<array{letter: string, metric: string, statistic: string}>, formula: string, format: string, valueType: ?string}
 * @phpstan-type MetricQuerySeries array{group: ?string, total: array{value: int|float|null, rows: int}, points: list<MetricQueryPoint>, rows: list<MetricQueryRow>, rowsTotal: int, comparison?: ?MetricQueryComparison}
 */
#[McpTool(name: self::NAME, description: 'Read one metric of this project over time. metric_list lists each metric with the units, statistics and groups it takes, and a refused combination names the values to use. metric is a key from metric_list, which includes the metrics that the workflow template of the project declares. bucket-time:<name> reads the time of the main-session tool calls of a run in the bucket <name>, a name of 1 to 64 characters of a-z, 0-9, _ and -, and metric_list gives one entry per bucket of the project. A run with no time in a bucket gives 0, and a run with no bucket data has an unknown value. bucket-time-all:<name> reads the time of the tool calls in the bucket with the calls of subagents included, which can exceed the run time, and bucket-calls:<name> reads how many tool calls the run made in the bucket, subagent calls included. Both take the same names, and a run that a recompute has not reached since the release has an unknown value. harness-events:<kind> reads how many harness events of one kind a run held, such as harness-events:diagnostics, a kind being 1 to 64 characters of a-z, 0-9, _, . and -, and metric_list gives one entry per kind of the project. A run that reported events but none of this kind gives 0, and a run that reported none has an unknown value. unit is run, card or reading. A reading row is one stored reading of a usage window of an account, placed at the time it was read, and only account-usage takes the reading unit. account-usage reads the percent of the limit used, as each reading gave it, from the named bridges that follow the project, and takes the group account-window alone, which names the series bridge/account window, as in laptop/work session. session-usage and week-usage read the change in that percent over a run, from its first to its last reading inside the run, in percentage points, and take the run unit alone. A run row is one closed run, placed at its end, or at the time its first report arrived when it has no end. A card row is one finished card, placed at the time it was completed. A card whose runs used two or more variants gives one row per variant. Each row carries the whole card outcome, so the card counts once per variant. The metrics start with the oldest run that the server held at the upgrade, so a run deleted before then is not counted. statistic is median, mean, sum, p90, count or max, and a metric offers the ones that fit its value. group splits the rows into one series per stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window, wake-gap or wake-cache, and none gives one series. wake-gap and wake-cache take the metrics wakes, cold-wakes, cold-wake-cache-write-tokens and cold-wake-share alone. wake-gap names the gap between the last turn of the session and the resume as under-5m, 5m-to-1h or over-1h, and wake-cache adds -warm or -cold, as in over-1h-cold. A cold wake wrote more tokens to the prompt cache than it read from the input and the cache together. The series come in that order, and a run that is no wake goes in the series with no group. range is seven-days, thirty-days, ninety-days or all, and the default is thirty-days. bucket is day, week or month, and a bucket starts at UTC midnight, on the ISO Monday or on the first of the month. seven-days takes the bucket day alone. bucket-time-each, bucket-time-all-each and bucket-calls-each take no name and group none: each bucket of the project rules is one series, and a bucket that a run did not touch counts 0, so the means of the series add up to the mean of the whole. A money value is in US dollars. A duration is in milliseconds, except a duration that the workflow template declares, which is in hours. A ratio is between 0 and 1. A percent is a number of percent, such as 96 for 96 percent, and it can pass 100. A row whose value is unknown has a null value and stays out of every statistic. Each series has group, total, points, rows and rowsTotal. group is null for the rows with no group. total has value, the statistic over all the rows, and rows, the number of rows with a known value. points has one entry per bucket with rows, oldest first, each with start, value and rows. rows lists at most 100 rows, newest first, each with id, cardNumber, time and value. id is the run id or the card id. rowsTotal is the number of rows before the cut. The series with no group comes last. compare is off or previous, and the default is off. With previous, the tool reads the period of the same length just before the range, and the output gains window, with the start and the end of each period. Each series then gains comparison, which holds previous, the statistic over the previous period with value and rows, and change, with value and kind. The kind is relative, a fraction of the previous value, points for the difference of two rates, or absolute in the unit of the metric for a percent or when the previous value is zero. change is null when a period has no known value. comparison also holds likelyRange with the current, previous and change ranges, each with low and high, and sign: clear when the likely ranges of the two periods do not overlap, not-clear-yet when they overlap, and not-enough-data when a period has fewer than 5 distinct cards with a known value. The sign and the likely ranges are null for the sum, the count and the max statistics, and for the reading unit, because a reading belongs to no card. The ranges widen with the number of series, so a graph with many series shows a clear sign more rarely. compare previous refuses the range all. To combine metrics, leave metric and statistic out and pass rows, formula and format. rows is up to six objects with a letter A to F, a metric and a statistic, and the metrics must add up over runs, which excludes stop-rate, peak-context, cold-wake-share, the usage metrics, the grouped bucket metrics and the metrics of the workflow template. formula combines the letters with + - * / and parentheses, such as A / B, in at most 200 characters, and the letters need not follow each other. format is auto, number, percent, money or duration, and auto keeps money and duration and shows every other result as a number. Each row reads its statistic for each bucket and group, and then the formula combines the results, so A / B with two sums is the ratio of the two sums. unit and group must be allowed for every row. A point where a row has no known value gives a gap, except a count row, which reads 0, and a division by zero gives a gap too. A formula series has no value per run, so its rows list is empty and rowsTotal counts the runs or cards. The output then holds formula, with the rows, the formula text, the format and the value type of the result, and metric and statistic are null.')]
final readonly class MetricQueryTool
{
    public const string NAME = 'metric_query';

    public const int MAX_ROWS = 100;

    public const array ROW_ITEM = [
        'type' => 'object',
        'properties' => [
            'letter' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D', 'E', 'F'], 'description' => 'the letter that the formula uses for this row'],
            'metric' => ['type' => 'string', 'description' => 'the key of a metric from metric_list that adds up over runs'],
            'statistic' => ['type' => 'string', 'enum' => ['median', 'mean', 'sum', 'p90', 'count', 'max'], 'description' => 'the statistic that reads the metric for each bucket and group'],
        ],
        'required' => ['letter', 'metric', 'statistic'],
    ];

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private MetricQueryHandler $query,
        private TemplateMetrics $templateMetrics,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string       $unit      what one row stands for: run, card or reading
     * @param string       $metric    the key of a metric from metric_list, such as cost, duration, stop-rate, account-usage, session-usage, week-usage, bucket-time:<name>, bucket-time-all:<name>, bucket-calls:<name> or harness-events:<kind>; leave it out when you pass rows
     * @param string       $statistic median, mean, sum, p90, count or max; leave it out when you pass rows
     * @param string       $group     stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window, wake-gap, wake-cache or none
     * @param string       $range     seven-days, thirty-days, ninety-days or all
     * @param string       $bucket    day, week or month; seven-days takes day alone
     * @param string       $compare   off, or previous to read the period of the same length just before the range
     * @param array<mixed> $rows      the lettered rows of a formula, each with letter, metric and statistic
     * @param string       $formula   the formula over the letters of the rows, such as A / B
     * @param string       $format    auto, number, percent, money or duration
     *
     * @return array{unit: string, metric: ?string, statistic: ?string, group: string, range: string, bucket: string, compare: string, window: array{current: MetricQueryWindow, previous: ?MetricQueryWindow}, series: list<MetricQuerySeries>, formula?: MetricQueryFormula}
     */
    public function __invoke(
        string $unit,
        string $metric = '',
        string $statistic = '',
        string $group = 'none',
        string $range = 'thirty-days',
        string $bucket = 'week',
        string $compare = 'off',
        #[Schema(items: self::ROW_ITEM, maxItems: MetricFormula::MAX_ROWS)] array $rows = [],
        string $formula = '',
        string $format = 'auto',
    ): array {
        try {
            $project = $this->subjects->requireReadableProject();
            $templates = $this->templateMetrics->forProject($project->requireId());
            $parsedFormula = $this->parseFormula($metric, $statistic, $rows, $formula, $format, $templates);
            if (null === $parsedFormula) {
                $earliest = null;
                $shape = self::parseMetric($metric, $templates);
                $parsedStatistic = self::parse(MetricStatistic::class, 'statistic', $statistic);
            } else {
                $earliest = $parsedFormula->earliestRow();
                if (null === $earliest) {
                    throw new ToolCallException($this->problemText($parsedFormula));
                }
                [$shape, $parsedStatistic] = [$earliest->shape, $earliest->statistic];
            }
            $command = new MetricQueryCommand(
                $project,
                self::parse(MetricUnit::class, 'unit', $unit),
                $shape,
                $parsedStatistic,
                self::parse(MetricGroup::class, 'group', $group),
                self::parse(MetricRange::class, 'range', $range),
                self::parse(MetricBucket::class, 'bucket', $bucket),
                self::parse(MetricCompare::class, 'compare', $compare),
                formula: $parsedFormula,
            );

            try {
                $view = ($this->query)($command);
            } catch (DomainErrors $e) {
                throw new ToolCallException($this->refusal($command, $e));
            }

            $output = [
                'unit' => $command->unit->value,
                'metric' => null === $parsedFormula ? $shape->key->key() : null,
                'statistic' => null === $parsedFormula ? $command->statistic->value : null,
                'group' => $command->group->value,
                'range' => $command->range->value,
                'bucket' => $command->bucket->value,
                'compare' => $command->compare->value,
                'window' => ['current' => self::window($view->current), 'previous' => null === $view->previous ? null : self::window($view->previous)],
                'series' => array_map(static fn (MetricSeries $series): array => self::series($series, null !== $parsedFormula), $view->series),
            ];

            return null === $parsedFormula ? $output : $output + ['formula' => [
                'rows' => array_map(static fn (FormulaRow $row): array => ['letter' => $row->letter, 'metric' => $row->shape->key->key(), 'statistic' => $row->statistic->value], $parsedFormula->rows),
                'formula' => $parsedFormula->formula,
                'format' => $parsedFormula->format->value,
                'valueType' => $parsedFormula->valueType()?->value,
            ]];
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

    /**
     * A formula series holds one row with no value for each run or card, so its list is empty and the total counts them.
     *
     * @return MetricQuerySeries
     */
    private static function series(MetricSeries $series, bool $isFormula): array
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
            ], $isFormula ? [] : \array_slice($series->rows, 0, self::MAX_ROWS)),
            'rowsTotal' => \count($series->rows),
        ];

        return null === $series->comparison ? $output : $output + ['comparison' => self::comparison($series->comparison)];
    }

    /**
     * Null for a one-metric call. A call passes a metric and a statistic, or rows with a formula.
     *
     * @param array<mixed> $rows
     */
    private function parseFormula(string $metric, string $statistic, array $rows, string $formula, string $format, TemplateMetricSet $templates): ?MetricFormula
    {
        if ([] === $rows && '' === $formula) {
            if ('' === $metric || '' === $statistic) {
                throw new ToolCallException('Pass metric and statistic to read one metric, or rows and formula to combine metrics.');
            }

            return null;
        }
        if ('' !== $metric || '' !== $statistic) {
            throw new ToolCallException('Pass metric and statistic, or rows and formula, and not both.');
        }

        return MetricFormula::parse(self::rowsText($rows), $formula, $format, $templates);
    }

    /**
     * The rows text of the rows argument, such as `A:cache-read-tokens:sum,B:cache-write-tokens:sum`.
     *
     * @param array<mixed> $rows
     *
     * @throws ToolCallException when a row is no object of three strings with no comma
     */
    public static function rowsText(array $rows): string
    {
        $items = [];
        foreach ($rows as $row) {
            $letter = \is_array($row) ? $row['letter'] ?? null : null;
            $key = \is_array($row) ? $row['metric'] ?? null : null;
            $rowStatistic = \is_array($row) ? $row['statistic'] ?? null : null;
            if (!\is_string($letter) || !\is_string($key) || !\is_string($rowStatistic) || str_contains($letter.$key.$rowStatistic, ',')) {
                throw new ToolCallException('Each row needs a letter, a metric and a statistic, as strings with no comma.');
            }
            $items[] = $letter.':'.$key.':'.$rowStatistic;
        }

        return implode(',', $items);
    }

    private function problemText(MetricFormula $formula): string
    {
        $problem = $formula->problem ?? throw new \LogicException('A formula with no row has a problem.');

        return $problem->english($this->translator);
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

    private function refusal(MetricQueryCommand $command, DomainErrors $errors): string
    {
        $metric = $command->metric;
        $formula = $command->formula;
        $parts = [];
        foreach (array_keys($errors->errors) as $field) {
            if (MetricQueryHandler::FORMULA_FIELD === $field) {
                $parts[] = $this->problemText($formula ?? throw new \LogicException('Only a formula query has a formula error.'));
                continue;
            }
            if ('bucket' === $field) {
                $parts[] = \sprintf('The range %s does not take the bucket "%s". Use one of: %s.', $command->range->value, $command->bucket->value, self::valuesOf($command->range->allowedBuckets()));
                continue;
            }
            if ('compare' === $field) {
                $parts[] = \sprintf('The range %s has no previous period to compare with. Use compare off, or another range.', $command->range->value);
                continue;
            }
            [$given, $allowed] = match ($field) {
                'unit' => [$command->unit, $formula?->units() ?? $metric->units()],
                'statistic' => [$command->statistic, $metric->statistics()],
                'group' => [$command->group, $formula?->groups() ?? $metric->groups()],
                default => throw new \LogicException(\sprintf('The metric query refuses no field "%s".', $field)),
            };
            $parts[] = null === $formula
                ? \sprintf('The metric %s does not take the %s "%s". Use one of: %s.', $metric->key->key(), $field, $given->value, self::valuesOf($allowed))
                : \sprintf('The rows of this formula do not all take the %s "%s". Use one of: %s.', $field, $given->value, self::valuesOf($allowed));
        }
        if (!isset($errors->errors[MetricQueryHandler::FORMULA_FIELD])) {
            $parts[] = 'metric_list lists the valid combinations.';
        }

        return implode(' ', $parts);
    }

    /** @param list<\BackedEnum> $cases */
    private static function valuesOf(array $cases): string
    {
        return implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
