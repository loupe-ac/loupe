<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\Service\KeptGraphView;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Insights\View\MetricTitle;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Uid\Uuid;

final class KeptGraphViewTest extends TestCase
{
    public function test_the_same_kept_result_loses_its_open_path_at_the_utc_boundary_of_the_week(): void
    {
        $kept = self::kept();

        $before = self::layout($kept, '2026-11-08 23:59:59 UTC');
        $after = self::layout($kept, '2026-11-09 00:00:00 UTC');

        self::assertNotSame('', $before->lines[0]->openPath);
        self::assertSame('', $after->lines[0]->openPath);
        self::assertTrue(str_starts_with($after->lines[0]->path, $before->lines[0]->path));
    }

    public function test_the_boundary_follows_utc_and_not_the_zone_of_the_clock(): void
    {
        $kept = self::kept();

        self::assertNotSame('', self::layout($kept, '2026-11-09 01:00:00 +02:00')->lines[0]->openPath);
        self::assertSame('', self::layout($kept, '2026-11-08 20:00:00 -04:00')->lines[0]->openPath);
    }

    private static function layout(KeptGraphResult $kept, string $now): LineLayout
    {
        $layout = self::view(new MockClock($now))->build($kept, 'en')->layout;
        self::assertInstanceOf(LineLayout::class, $layout);

        return $layout;
    }

    private static function kept(): KeptGraphResult
    {
        $computedAt = new \DateTimeImmutable('2026-11-08 12:00:00 UTC');
        $base = ChartSeries::of(null, ['2026-10-26' => 1.0, '2026-11-02' => 2.0]);
        $series = new MetricSeries($base->group, $base->points, $base->total, [new MetricRow(Uuid::v7(), 1, null, new \DateTimeImmutable('2026-11-02 UTC'), 2.0)]);

        return new KeptGraphResult(
            $computedAt,
            new MetricsQuery(bucket: MetricBucket::Week),
            [$series],
            [],
            new MetricWindow(new \DateTimeImmutable('2026-10-09 00:00:00 UTC'), $computedAt),
            null,
        );
    }

    private static function view(MockClock $clock): KeptGraphView
    {
        $translator = new Translator('en');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', \dirname(__DIR__, 4).'/translations/messages.en.xlf', 'en');

        return new KeptGraphView(new MetricTitle($translator), $translator, $clock);
    }
}
