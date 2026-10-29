<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExperimentMetricTest extends TestCase
{
    /** @return iterable<string, array{MetricShape, string}> */
    public static function formats(): iterable
    {
        yield 'money' => [new MetricShape(new MetricKey(Metric::Cost)), 'currency'];
        yield 'built-in ratio' => [new MetricShape(new MetricKey(Metric::StopRate)), 'percent'];
        yield 'tokens' => [new MetricShape(new MetricKey(Metric::OutputTokens)), 'integer'];
        yield 'run duration' => [new MetricShape(new MetricKey(Metric::Duration)), 'minutes'];
        yield 'template rate' => [MetricShape::template(new TemplateMetric('merge-rate', 'Merge rate', 'Merged.', MetricType::Rate, outcome: 'merged')), 'percent'];
        yield 'template count' => [MetricShape::template(new TemplateMetric('fix-rounds', 'Fix rounds', 'Fixes.', MetricType::Count, new CountFilter(role: 'fix'))), 'decimal'];
        yield 'template duration in hours' => [MetricShape::template(new TemplateMetric('hours', 'Hours', 'Hours.', MetricType::Duration, from: new DurationEvent('pr-opened'), to: new DurationEvent('pr-merged'))), 'decimal'];
    }

    #[DataProvider('formats')]
    public function test_each_row_takes_a_format(MetricShape $shape, string $format): void
    {
        self::assertSame($format, new ExperimentMetric($shape->key->key(), [], false, [], $shape)->format());
    }

    public function test_a_part_row_has_no_shape_and_shows_a_decimal(): void
    {
        $part = new ExperimentMetric('conflict', [], false);

        self::assertNull($part->valueType());
        self::assertSame('decimal', $part->format());
    }

    public function test_a_row_with_no_baseline_has_no_clear_flag(): void
    {
        self::assertNull(new ExperimentMetric('cost', [], null)->clear);
        self::assertTrue(new ExperimentMetric('cost', [], true)->clear);
    }
}
