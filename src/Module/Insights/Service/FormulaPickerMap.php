<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Workflow\Contract\TemplateMetricSet;

/**
 * What the Metrics tab needs to narrow a formula's controls without a visit: for each metric that can join a
 * formula, the Units, Groups and Statistics it allows. The rules stay in PHP, and the page only reads this map.
 */
final class FormulaPickerMap
{
    /**
     * @param list<MetricShape> $metrics           the metrics of the picker
     * @param list<string>      $bucketNames
     * @param list<string>      $harnessEventKinds
     * @param list<string>      $extraKeys         metric keys that a formula already names
     *
     * @return array<string, array{units: list<string>, groups: list<string>, statistics: list<string>}>
     */
    public static function of(array $metrics, array $bucketNames, array $harnessEventKinds, array $extraKeys, TemplateMetricSet $templates): array
    {
        $keys = array_map(static fn (MetricShape $shape): string => $shape->key->key(), $metrics);
        foreach ([Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls] as $family) {
            foreach ($bucketNames as $name) {
                $keys[] = $family->value.':'.$name;
            }
        }
        foreach ($harnessEventKinds as $kind) {
            $keys[] = Metric::HarnessEvents->value.':'.$kind;
        }

        $map = [];
        foreach ([...$keys, ...$extraKeys] as $key) {
            $metricKey = MetricKey::tryParse($key, $templates);
            $shape = null === $metricKey ? null : MetricShape::of($metricKey, $templates);
            if (null === $shape || !MetricFormula::canJoin($shape)) {
                continue;
            }
            $map[$key] = [
                'units' => array_map(static fn (\BackedEnum $unit): string => (string) $unit->value, $shape->units()),
                'groups' => array_map(static fn (\BackedEnum $group): string => (string) $group->value, $shape->groups()),
                'statistics' => array_map(static fn (\BackedEnum $statistic): string => (string) $statistic->value, $shape->statistics()),
            ];
        }

        return $map;
    }
}
