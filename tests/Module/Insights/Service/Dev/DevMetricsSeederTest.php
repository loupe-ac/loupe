<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Service\Dev\DevMetricsCardSeeder;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\Dev\DevMetricsSeeder;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Insights\Service\PublicLinkCode;
use App\Module\Project\Entity\Project;
use App\Tests\DevSeed\SeedContextFactory;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DevMetricsSeederTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use InsightsScenario;

    private const string NOW = '2026-10-31 15:00:00';

    /** Three models a day, and a fourth run every third day. */
    private const int RUNS = DevMetricsSeeder::DAYS * 3 + DevMetricsSeeder::DAYS / 3;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_seeds_runs_of_three_models_over_both_periods_and_seven_saved_graphs(): void
    {
        $project = $this->projectWithColumns('dev-metrics');

        self::assertTrue($this->seed($project, self::NOW));

        $this->em()->clear();
        $runs = $this->demoRuns($project);
        self::assertCount(self::RUNS, $runs);
        $models = array_values(array_unique(array_map(static fn (WorkerRun $run): ?string => $run->model, $runs)));
        sort($models);
        self::assertSame(['claude-haiku-5-5', 'claude-opus-5-5', 'claude-sonnet-5-5'], $models);

        $this->assertEachRunLinksAStoredCard($project, $runs);
        self::assertGreaterThanOrEqual(Stats::MIN_FINISHED_CARDS, $this->cardsBetween($runs, '2026-10-01', '2026-10-31'), 'The current period has enough cards for the compare sign.');
        self::assertGreaterThanOrEqual(Stats::MIN_FINISHED_CARDS, $this->cardsBetween($runs, '2026-09-01', '2026-10-01'), 'The previous period has enough cards for the compare sign.');

        $days = $this->days($runs);
        sort($days);
        self::assertSame('2026-10-30', end($days), 'The newest runs are from yesterday, so none starts in the future.');
        self::assertSame('2026-09-01', $days[0] ?? null, 'The previous period of a 30 day range holds runs too.');

        self::assertNotSame([], $this->service(WorkerRunBucketTimeRepository::class)->findBucketNamesOfProject($project));
        self::assertSame(
            ['Cost per run by model', 'Cost per run', 'Runs by model', 'Time in each bucket', 'Share of runs by model', 'Cost per run by account', 'Waiting for the worker'],
            array_map(static fn ($graph): string => $graph->name, $this->service(InsightsSavedGraphRepository::class)->findActive($project)),
        );
    }

    public function test_each_saved_graph_stores_a_unit_that_its_metric_allows(): void
    {
        $project = $this->projectWithColumns('dev-metrics-units');

        $this->seed($project, self::NOW);

        $graphs = $this->service(InsightsSavedGraphRepository::class)->findActive($project);
        self::assertCount(7, $graphs);
        foreach ($graphs as $graph) {
            self::assertContains($graph->unit, Metric::from($graph->metric)->units(), $graph->name);
        }
    }

    public function test_only_the_waiting_graph_keeps_no_result(): void
    {
        $project = $this->projectWithColumns('dev-metrics-waiting');

        $this->seed($project, self::NOW);
        $this->seed($project, self::NOW);

        $this->em()->clear();
        $kept = $this->service(InsightsKeptResultRepository::class);
        foreach ($this->service(InsightsSavedGraphRepository::class)->findActive($project) as $graph) {
            $waiting = DevSeedId::of('insights/graph/'.DevMetricsSeeder::WAITING_GRAPH_SLUG)->equals($graph->id);
            self::assertSame($waiting, null === $kept->findForGraph($graph), $graph->name);
        }
    }

    public function test_a_reseed_makes_the_waiting_graph_wait_again_after_the_worker_kept_it(): void
    {
        $project = $this->projectWithColumns('dev-metrics-rewait');
        $this->seed($project, self::NOW);

        $graphs = $this->service(InsightsSavedGraphRepository::class);
        $waiting = DevSeedId::of('insights/graph/'.DevMetricsSeeder::WAITING_GRAPH_SLUG);
        $graph = null;
        foreach ($graphs->findActive($project) as $candidate) {
            if ($waiting->equals($candidate->id)) {
                $graph = $candidate;
            }
        }
        self::assertNotNull($graph);
        $this->service(InsightsResultKeeper::class)->graph($graph);
        $kept = $this->service(InsightsKeptResultRepository::class);
        self::assertNotNull($kept->findForGraph($graph));

        self::assertTrue($this->seed($project, self::NOW));
        self::assertNull($kept->findForGraph($graph));
        self::assertFalse($this->seed($project, self::NOW));
    }

    public function test_a_second_run_on_the_same_day_adds_nothing(): void
    {
        $project = $this->projectWithColumns('dev-metrics-twice');
        $this->seed($project, self::NOW);

        $cards = $this->service(CardRepository::class)->count(['project' => $project]);

        self::assertFalse($this->seed($project, self::NOW));
        self::assertSame($cards, $this->service(CardRepository::class)->count(['project' => $project]));

        $this->em()->clear();
        self::assertCount(self::RUNS, $this->demoRuns($project));
        self::assertCount(7, $this->service(InsightsSavedGraphRepository::class)->findActive($project));
    }

    public function test_one_graph_split_by_account_is_shared_with_a_fixed_code(): void
    {
        $project = $this->projectWithColumns('dev-metrics-share');

        $this->seed($project, self::NOW);

        $this->em()->clear();
        $link = $this->service(PublicLinkRepository::class)->find(DevSeedId::of('insights/public-link/cost-per-run-by-account'));
        self::assertInstanceOf(PublicLink::class, $link);
        self::assertSame(DevMetricsSeeder::SHARED_GRAPH_CODE, $link->code);
        self::assertTrue($link->enabled);
        self::assertSame(PublicLinkKind::Graph, $link->kind);
        self::assertSame('Cost per run by account', $link->savedGraph?->name);
        self::assertSame(MetricGroup::Account, $link->savedGraph->group);
        self::assertMatchesRegularExpression('/^'.PublicLinkCode::PATTERN.'$/', $link->code);
        self::assertSame($project->id?->toRfc4122(), $link->project->id?->toRfc4122());
    }

    public function test_the_demo_runs_carry_two_accounts_so_the_split_has_series(): void
    {
        $project = $this->projectWithColumns('dev-metrics-accounts');

        $this->seed($project, self::NOW);

        $this->em()->clear();
        $accounts = array_values(array_unique(array_map(static fn (WorkerRun $run): ?string => $run->account, $this->demoRuns($project))));
        sort($accounts);
        self::assertSame(['dev-personal', 'dev-work'], $accounts);
        $facts = array_values(array_unique(array_map(static fn ($fact): ?string => $fact->account, $this->service(WorkerRunFactRepository::class)->findBy(['project' => $project->id]))));
        sort($facts);
        self::assertSame(['dev-personal', 'dev-work'], $facts);
    }

    public function test_a_stored_run_with_no_account_gets_one_and_a_deleted_link_comes_back_once(): void
    {
        $project = $this->projectWithColumns('dev-metrics-account-fill');
        $this->seed($project, self::NOW);
        $id = DevSeedId::of('insights/run/2026-10-28/1');
        $connection = $this->em()->getConnection();
        $connection->executeStatement('UPDATE bridge_worker_runs SET account = NULL WHERE id = ?', [$id->toRfc4122()]);
        $connection->executeStatement('UPDATE bridge_worker_run_facts SET account = NULL WHERE run_id = ?', [$id->toRfc4122()]);
        $connection->executeStatement('DELETE FROM insights_public_links');
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');

        self::assertTrue($this->seed($project, self::NOW));

        $this->em()->clear();
        self::assertNotNull($this->service(WorkerRunRepository::class)->find($id)?->account);
        self::assertNotNull($this->service(WorkerRunFactRepository::class)->find($id)?->account);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM insights_public_links'));
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');
        self::assertFalse($this->seed($project, self::NOW), 'A later run has nothing left to do.');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM insights_public_links'));
    }

    public function test_a_stored_run_with_no_peak_gets_one_and_its_fact_row_follows(): void
    {
        $project = $this->projectWithColumns('dev-metrics-peak');
        $this->seed($project, self::NOW);
        $id = DevSeedId::of('insights/run/2026-10-28/1');
        $connection = $this->em()->getConnection();
        $connection->executeStatement('UPDATE bridge_worker_runs SET peak_context_tokens = NULL WHERE id = ?', [$id->toRfc4122()]);
        $connection->executeStatement('UPDATE bridge_worker_run_facts SET peak_context_tokens = NULL WHERE run_id = ?', [$id->toRfc4122()]);
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');

        self::assertTrue($this->seed($project, self::NOW));

        $this->em()->clear();
        $peak = $this->service(WorkerRunRepository::class)->find($id)?->peakContextTokens;
        self::assertNotNull($peak);
        self::assertGreaterThanOrEqual(90_000, $peak);
        self::assertLessThanOrEqual(260_000, $peak);
        self::assertSame($peak, $this->service(WorkerRunFactRepository::class)->find($id)?->peakContextTokens);
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');
        self::assertFalse($this->seed($project, self::NOW), 'A run with a peak has nothing left to do.');
    }

    public function test_a_run_on_a_later_day_fills_only_the_new_days(): void
    {
        $project = $this->projectWithColumns('dev-metrics-later');
        $this->seed($project, self::NOW);

        self::assertTrue($this->seed($project, '2026-11-02 09:00:00'));

        $this->em()->clear();
        $days = array_count_values($this->days($this->demoRuns($project)));
        ksort($days);
        self::assertSame('2026-11-01', array_key_last($days));
        self::assertSame(3, $days['2026-11-01'], 'A day that lacked runs gains one run per model.');
        self::assertSame(4, $days['2026-10-31'], 'The first day of a span also gains a fourth run.');
        self::assertSame(4, $days['2026-10-28'], 'A day that held runs gains none.');
        self::assertSame(3, $days['2026-10-27'], 'A day that held runs gains none when its distance from today changes.');
        self::assertCount(7, $this->service(InsightsSavedGraphRepository::class)->findActive($project));
    }

    public function test_the_runs_the_cards_and_the_graphs_have_fixed_ids(): void
    {
        $project = $this->projectWithColumns('dev-metrics-ids');
        $this->seed($project, self::NOW);

        $this->em()->clear();
        $runs = $this->service(WorkerRunRepository::class);
        self::assertNotNull($runs->find(DevSeedId::of('insights/run/2026-10-30/0')));
        self::assertNotNull($runs->find(DevSeedId::of('insights/run/2026-10-30/2')));
        self::assertNotNull($this->service(InsightsSavedGraphRepository::class)->find(DevSeedId::of('insights/graph/cost-per-run')));
        $cardIds = array_map(static fn (WorkerRun $run): string => (string) $run->cardId(), $this->demoRuns($project));
        self::assertContains((string) DevSeedId::of('board/card/demo-runs-2026-10-28'), $cardIds);
    }

    public function test_a_card_that_a_person_deleted_comes_back_and_its_runs_take_its_new_number(): void
    {
        $project = $this->projectWithColumns('dev-metrics-restored');
        $this->seed($project, self::NOW);
        $cards = $this->service(CardRepository::class);
        $card = $cards->find(DevSeedId::of('board/card/demo-runs-2026-10-28')) ?? throw new \LogicException('The card is missing.');
        $oldNumber = $card->number;
        $this->em()->remove($card);
        $this->em()->flush();

        self::assertTrue($this->seed($project, self::NOW));

        $this->em()->clear();
        $runs = $this->demoRuns($project);
        self::assertCount(self::RUNS, $runs);
        self::assertNotNull($this->service(WorkerRunRepository::class)->find(DevSeedId::of('insights/run/2026-10-28/0')));
        $restored = $cards->find(DevSeedId::of('board/card/demo-runs-2026-10-28')) ?? throw new \LogicException('The card did not come back.');
        self::assertNotSame($oldNumber, $restored->number);
        $this->assertEachRunLinksAStoredCard($project, $runs);
        foreach ($this->service(WorkerRunFactRepository::class)->findBy(['project' => $project->id, 'subjectId' => $restored->id]) as $fact) {
            self::assertSame($restored->number, $fact->cardNumber);
        }
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');
        self::assertFalse($this->seed($project, self::NOW), 'A later run has nothing left to do.');
    }

    public function test_a_deleted_run_comes_back_with_its_fixed_id_on_a_day_that_keeps_other_runs(): void
    {
        $project = $this->projectWithColumns('dev-metrics-deleted-run');
        $this->seed($project, self::NOW);
        $id = DevSeedId::of('insights/run/2026-10-28/1');
        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [$id->toRfc4122()]);
        $this->em()->clear();
        self::assertCount(self::RUNS - 1, $this->demoRuns($project));
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');

        self::assertTrue($this->seed($project, self::NOW));

        $this->em()->clear();
        self::assertCount(self::RUNS, $this->demoRuns($project));
        self::assertNotNull($this->service(WorkerRunRepository::class)->find($id));
        self::assertNotNull($this->service(WorkerRunFactRepository::class)->find($id));
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is missing.');
        self::assertFalse($this->seed($project, self::NOW), 'A later run has nothing left to do.');
    }

    public function test_it_replaces_a_demo_run_whose_card_does_not_exist(): void
    {
        $project = $this->projectWithColumns('dev-metrics-stale');
        $stale = $this->seedRun($this->em(), $project, cardNumber: 261030, bridgeId: Uuid::fromString(DevMetricsSeeder::BRIDGE), cardId: Uuid::v4());
        $staleId = $stale->id;

        self::assertTrue($this->seed($project, self::NOW));

        $this->em()->clear();
        $runs = $this->demoRuns($project);
        self::assertCount(self::RUNS, $runs);
        self::assertNull($this->service(WorkerRunRepository::class)->find($staleId));
        self::assertNull($this->service(WorkerRunFactRepository::class)->find($staleId));
        $this->assertEachRunLinksAStoredCard($project, $runs);
    }

    /**
     * The run drawer links each run to the board card that cardId() names.
     *
     * @param list<WorkerRun> $runs
     */
    private function assertEachRunLinksAStoredCard(Project $project, array $runs): void
    {
        $cards = $this->service(CardRepository::class);
        foreach ($runs as $run) {
            $card = $cards->findOneBy(['id' => $run->cardId(), 'project' => $project->id]);
            self::assertInstanceOf(Card::class, $card);
            self::assertSame($card->number, $run->cardNumber);
            self::assertSame('done', $card->column->slug);
        }
    }

    /** @param list<WorkerRun> $runs */
    private function cardsBetween(array $runs, string $from, string $to): int
    {
        $numbers = [];
        foreach ($runs as $run) {
            $day = $run->startedAt?->format('Y-m-d') ?? '';
            if ($day >= $from && $day < $to) {
                $numbers[] = $run->cardNumber;
            }
        }

        return \count(array_unique($numbers));
    }

    private function projectWithColumns(string $name): Project
    {
        $project = $this->scenarioProject($name);
        $this->seedColumns($project);
        $this->em()->flush();

        return $project;
    }

    private function seed(Project $project, string $now): bool
    {
        $context = SeedContextFactory::create(self::getContainer(), now: new \DateTimeImmutable($now));
        $cards = $this->service(DevMetricsCardSeeder::class);

        return $this->service(DevMetricsSeeder::class)->seed(
            $context,
            $project,
            static fn (\DateTimeImmutable $from, \DateTimeImmutable $to): array => $cards->cardFor($context, $project, $from, $to),
        );
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
