<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Experiment\Interval;

/**
 * The wire shape of a metric query result, shared by the MCP tool and the public API.
 *
 * @phpstan-type MetricQueryPoint array{start: string, value: int|float|null, rows: int}
 * @phpstan-type MetricQueryRow array{id: string, cardNumber: ?int, time: string, value: int|float|null}
 * @phpstan-type MetricQueryWindow array{from: ?string, to: string}
 * @phpstan-type MetricQueryRange array{low: float, high: float}
 * @phpstan-type MetricQueryComparison array{previous: array{value: int|float|null, rows: int}, change: ?array{value: float, kind: string}, likelyRange: ?array{current: ?MetricQueryRange, previous: ?MetricQueryRange, change: ?MetricQueryRange}, sign: ?string}
 * @phpstan-type MetricQuerySeries array{group: ?string, total: array{value: int|float|null, rows: int}, points: list<MetricQueryPoint>, rows: list<MetricQueryRow>, rowsTotal: int, comparison?: ?MetricQueryComparison}
 */
final readonly class MetricQueryPayload
{
    public const int MAX_ROWS = 100;

    /** @return MetricQueryWindow */
    public static function window(MetricWindow $window): array
    {
        return ['from' => $window->from?->format(\DATE_ATOM), 'to' => $window->to->format(\DATE_ATOM)];
    }

    /** @return ?MetricQueryComparison */
    public static function comparison(?MetricComparison $comparison): ?array
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
    public static function series(MetricSeries $series, bool $isFormula): array
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
}
