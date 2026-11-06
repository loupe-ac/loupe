<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Insights\Service\PublicExperimentReport;
use App\Module\Insights\View\PublicExperimentResult;
use App\Tests\Module\Insights\InsightsScenario;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PublicExperimentReportTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_report_is_computed_once_and_kept_for_fifteen_minutes(): void
    {
        $project = $this->scenarioProject('report-kept');
        $this->seedExperiment($project, 'trial');
        $clock = new MockClock('2026-03-01 10:00:00');
        $report = $this->report($clock, new ArrayAdapter(clock: $clock));

        $first = $report->forExperiment($project, 'trial');
        $clock->modify('+14 minutes');
        $kept = $report->forExperiment($project, 'trial');
        $clock->modify('+2 minutes');
        $again = $report->forExperiment($project, 'trial');

        self::assertSame('2026-03-01 10:00:00', $first?->computedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 10:00:00', $kept?->computedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 10:16:00', $again?->computedAt->format('Y-m-d H:i:s'));
    }

    public function test_the_kept_result_holds_no_card_and_no_project(): void
    {
        $project = $this->scenarioProject('report-nocards');
        $this->seedExperiment($project, 'trial');

        $this->report(new MockClock())->forExperiment($project, 'trial');

        $item = $this->cache()->getItem($this->report(new MockClock())->key($project, 'trial'));
        $kept = $item->get();
        self::assertInstanceOf(PublicExperimentResult::class, $kept);
        self::assertSame([], array_values(array_filter(
            array_keys(get_object_vars($kept)),
            static fn (string $property): bool => \in_array($property, ['cards', 'project'], true),
        )));
        self::assertStringNotContainsString('Project report-nocards', serialize($kept));
    }

    public function test_an_unknown_experiment_gives_null_and_keeps_nothing(): void
    {
        $project = $this->scenarioProject('report-unknown');

        self::assertNull($this->report(new MockClock())->forExperiment($project, 'ghost'));
        self::assertFalse($this->cache()->getItem($this->report(new MockClock())->key($project, 'ghost'))->isHit());
    }

    public function test_the_key_names_the_project_the_experiment_and_the_locale(): void
    {
        $report = $this->report(new MockClock());
        $one = $this->scenarioProject('report-key-one');
        $two = $this->scenarioProject('report-key-two');
        $previous = \Locale::getDefault();

        try {
            \Locale::setDefault('en');
            $english = $report->key($one, 'trial');
            \Locale::setDefault('fr');
            $french = $report->key($one, 'trial');
        } finally {
            \Locale::setDefault($previous);
        }

        self::assertNotSame($english, $french);
        self::assertNotSame($report->key($one, 'trial'), $report->key($two, 'trial'));
        self::assertNotSame($report->key($one, 'trial'), $report->key($one, 'other'));
    }

    private function report(ClockInterface $clock, ?CacheItemPoolInterface $cache = null): PublicExperimentReport
    {
        $show = self::getContainer()->get(ShowExperimentHandler::class);
        self::assertInstanceOf(ShowExperimentHandler::class, $show);

        return new PublicExperimentReport($cache ?? $this->cache(), $show, $clock);
    }

    private function cache(): CacheItemPoolInterface
    {
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);

        return $cache;
    }
}
