<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsKeptResult;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\Service\InsightsRefreshSettings;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Insights\Service\PublicExperimentReport;
use App\Module\Insights\View\PublicExperimentResult;
use App\Tests\Module\Insights\InsightsScenario;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class PublicExperimentReportTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_first_visitor_computes_the_report_and_keeps_it(): void
    {
        $project = $this->scenarioProject('report-kept');
        $this->seedExperiment($project, 'trial');

        $first = $this->report(new MockClock())->forExperiment($project, 'trial');

        self::assertNotNull($first);
        self::assertNotNull($this->kept()->findForExperiment($project, 'trial'));
    }

    public function test_a_report_younger_than_the_sweep_interval_is_used_as_it_is(): void
    {
        $project = $this->scenarioProject('report-fresh');
        $this->seedExperiment($project, 'trial');
        $first = $this->report($this->now())->forExperiment($project, 'trial');
        usleep(1_100_000);

        $again = $this->report($this->now())->forExperiment($project, 'trial');

        self::assertEquals($first?->computedAt, $again?->computedAt);
    }

    public function test_a_report_older_than_the_sweep_interval_is_computed_again(): void
    {
        $project = $this->scenarioProject('report-old');
        $this->seedExperiment($project, 'trial');
        $first = $this->report($this->now())->forExperiment($project, 'trial');
        usleep(1_100_000);

        $again = $this->report(new MockClock($this->now()->now()->modify('+20 minutes')))->forExperiment($project, 'trial');

        self::assertGreaterThan($first?->computedAt, $again?->computedAt);
    }

    public function test_the_kept_result_holds_no_card_and_no_project(): void
    {
        $project = $this->scenarioProject('report-nocards');
        $this->seedExperiment($project, 'trial');

        $this->report(new MockClock())->forExperiment($project, 'trial');

        $kept = InsightsKeptResult::decode((string) $this->kept()->findForExperiment($project, 'trial')?->payload);
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
        self::assertNull($this->kept()->findForExperiment($project, 'ghost'));
    }

    private function now(): ClockInterface
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock;
    }

    private function report(ClockInterface $clock): PublicExperimentReport
    {
        $keeper = self::getContainer()->get(InsightsResultKeeper::class);
        $settings = self::getContainer()->get(InsightsRefreshSettings::class);
        self::assertInstanceOf(InsightsResultKeeper::class, $keeper);
        self::assertInstanceOf(InsightsRefreshSettings::class, $settings);

        return new PublicExperimentReport($this->kept(), $keeper, $settings, $clock);
    }

    private function kept(): InsightsKeptResultRepository
    {
        $kept = self::getContainer()->get(InsightsKeptResultRepository::class);
        self::assertInstanceOf(InsightsKeptResultRepository::class, $kept);

        return $kept;
    }
}
