<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service\Dev;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\Dev\DevMetricsSeeder;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class DevMetricsSeederTest extends KernelTestCase
{
    use InsightsScenario;

    private const string NOW = '2026-10-31 15:00:00';

    /** Three models a day, and a fourth run every third day. */
    private const int RUNS = DevMetricsSeeder::DAYS * 3 + DevMetricsSeeder::DAYS / 3;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_seeds_runs_of_three_models_over_both_periods_and_five_saved_graphs(): void
    {
        $project = $this->scenarioProject('dev-metrics');

        self::assertTrue($this->seeder(self::NOW)->seed($project));

        $this->em()->clear();
        $runs = $this->demoRuns($project);
        self::assertCount(self::RUNS, $runs);
        $models = array_values(array_unique(array_map(static fn (WorkerRun $run): ?string => $run->model, $runs)));
        sort($models);
        self::assertSame(['claude-haiku-5-5', 'claude-opus-5-5', 'claude-sonnet-5-5'], $models);

        $days = $this->days($runs);
        sort($days);
        self::assertSame('2026-10-30', end($days), 'The newest runs are from yesterday, so none starts in the future.');
        self::assertSame('2026-09-01', $days[0] ?? null, 'The previous period of a 30 day range holds runs too.');

        self::assertNotSame([], $this->service(WorkerRunBucketTimeRepository::class)->findBucketNamesOfProject($project));
        self::assertSame(
            ['Cost per run by model', 'Cost per run', 'Runs by model', 'Time in each bucket', 'Share of runs by model'],
            array_map(static fn ($graph): string => $graph->name, $this->service(InsightsSavedGraphRepository::class)->findActive($project)),
        );
    }

    public function test_a_second_run_on_the_same_day_adds_nothing(): void
    {
        $project = $this->scenarioProject('dev-metrics-twice');
        $this->seeder(self::NOW)->seed($project);

        self::assertFalse($this->seeder(self::NOW)->seed($project));

        $this->em()->clear();
        self::assertCount(self::RUNS, $this->demoRuns($project));
        self::assertCount(5, $this->service(InsightsSavedGraphRepository::class)->findActive($project));
    }

    public function test_a_run_on_a_later_day_fills_only_the_new_days(): void
    {
        $project = $this->scenarioProject('dev-metrics-later');
        $this->seeder(self::NOW)->seed($project);

        self::assertTrue($this->seeder('2026-11-02 09:00:00')->seed($project));

        $this->em()->clear();
        $days = array_count_values($this->days($this->demoRuns($project)));
        ksort($days);
        self::assertSame('2026-11-01', array_key_last($days));
        self::assertSame(3, $days['2026-10-31'], 'A day that lacked runs gains one run per model.');
        self::assertSame(4, $days['2026-10-28'], 'A day that held runs gains none.');
        self::assertCount(5, $this->service(InsightsSavedGraphRepository::class)->findActive($project));
    }

    /**
     * @param list<WorkerRun> $runs
     *
     * @return list<string>
     */
    private function days(array $runs): array
    {
        return array_map(static fn (WorkerRun $run): string => ($run->startedAt ?? throw new \LogicException('A demo run has a start.'))->format('Y-m-d'), $runs);
    }

    /** @return list<WorkerRun> */
    private function demoRuns(Project $project): array
    {
        return array_values($this->service(WorkerRunRepository::class)->findBy(['project' => $project->id, 'bridgeId' => Uuid::fromString(DevMetricsSeeder::BRIDGE)]));
    }

    private function seeder(string $now): DevMetricsSeeder
    {
        return new DevMetricsSeeder(
            $this->em(),
            $this->service(WorkerRunRepository::class),
            $this->service(InsightsSavedGraphRepository::class),
            $this->service(BucketTimeComputer::class),
            new MockClock($now),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
