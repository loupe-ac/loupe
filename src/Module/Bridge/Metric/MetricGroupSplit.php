<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** Splits metric rows into the lines of a graph and puts the lines in their order. */
final readonly class MetricGroupSplit
{
    /**
     * @param list<MetricRow> $rows
     *
     * @return array<string, array{label: string, group: ?string, groupKey: ?MetricGroupKey, rows: list<MetricRow>}> label is the group text with a prefix, or the empty string for no group
     */
    public static function split(array $rows): array
    {
        $byGroup = [];
        foreach ($rows as $row) {
            $label = null === $row->group ? '' : 'key:'.$row->group;
            $key = null === $row->groupKey ? $label : $label.'|'.$row->groupKey->id();
            $byGroup[$key] ??= ['label' => $label, 'group' => $row->group, 'groupKey' => $row->groupKey, 'rows' => []];
            $byGroup[$key]['rows'][] = $row;
        }

        return $byGroup;
    }

    /**
     * Series sort by label, with the key to break a tie. A grouping that buckets a number has a fixed order. The
     * series with no group goes last.
     *
     * @template T of array{label: string, group: ?string, groupKey: ?MetricGroupKey}
     *
     * @param array<string, T> $byGroup
     *
     * @return array<string, T>
     */
    public static function sort(array $byGroup, MetricGroup $group): array
    {
        $order = $group->seriesOrder() ?? [];
        $rank = static fn (?string $name): int => null === $name ? 0 : (false === ($at = array_search($name, $order, true)) ? \count($order) : $at);
        uasort($byGroup, static fn (array $a, array $b): int => [null === $a['group'], $rank($a['group']), $a['label'], $a['groupKey']?->id()] <=> [null === $b['group'], $rank($b['group']), $b['label'], $b['groupKey']?->id()]);

        return $byGroup;
    }
}
