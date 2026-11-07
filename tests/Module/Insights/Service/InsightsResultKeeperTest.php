<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Event\InsightsResultKept;
use App\Module\Insights\Messenger\RefreshInsightsItem;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\Service\InsightsComputeLocks;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class InsightsResultKeeperTest extends KernelTestCase
{
    use InsightsScenario;

    /** @var list<InsightsResultKept> */
    private array $events = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->events = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(InsightsResultKept::class, function (InsightsResultKept $event): void {
            $this->events[] = $event;
        });
    }

    public function test_a_keep_writes_one_row_and_announces_it(): void
    {
        $project = $this->scenarioProject('keeper-keep');
        $graph = $this->costGraph($project);

        $result = $this->keeper()->graph($graph);

        self::assertNotNull($this->kept()->findForGraph($graph));
        self::assertEquals($result->computedAt, $result->current->to);
        self::assertCount(1, $this->events);
        self::assertSame((string) $graph->id, $this->events[0]->graphId);
        self::assertSame((string) $project->id, $this->events[0]->projectId);
        self::assertNull($this->events[0]->experiment);
    }

    public function test_a_page_that_finds_the_lock_taken_computes_and_keeps_nothing(): void
    {
        $project = $this->scenarioProject('keeper-taken');
        $graph = $this->costGraph($project);
        $lock = $this->locks()->forGraph($graph->id ?? throw new \LogicException());
        self::assertTrue($lock->acquire());

        $this->keeper()->graph($graph);

        self::assertNull($this->kept()->findForGraph($graph));
        self::assertSame([], $this->events);
        self::assertTrue($lock->isAcquired(), 'A compute that never held the lock leaves it alone.');
        $lock->release();
    }

    public function test_the_lock_is_free_again_after_a_keep(): void
    {
        $project = $this->scenarioProject('keeper-release');
        $graph = $this->costGraph($project);

        $this->keeper()->graph($graph);

        $lock = $this->locks()->forGraph($graph->id ?? throw new \LogicException());
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    public function test_the_worker_path_reads_the_graph_after_the_lock_and_keeps_it(): void
    {
        $project = $this->scenarioProject('keeper-worker');
        $graph = $this->costGraph($project);
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $this->keeper()->graphById((string) $project->id, $graphId);

        self::assertCount(1, $this->events);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_kept_results WHERE saved_graph_id = :graph', ['graph' => $graphId]));
    }

    public function test_the_worker_path_keeps_nothing_for_a_removed_graph(): void
    {
        $project = $this->scenarioProject('keeper-removed');
        $graph = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('-1 hour'));
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $this->keeper()->graphById((string) $project->id, $graphId);

        self::assertSame([], $this->events);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_kept_results'));
    }

    public function test_the_worker_path_keeps_nothing_for_a_broken_graph(): void
    {
        $project = $this->scenarioProject('keeper-broken');
        $graph = $this->seedSavedGraph($project, 'Tests', 0);
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $this->keeper()->graphById((string) $project->id, $graphId);

        self::assertSame([], $this->events);
    }

    public function test_the_worker_path_ignores_an_id_that_names_no_graph(): void
    {
        $project = $this->scenarioProject('keeper-unknown');

        $this->keeper()->graphById((string) $project->id, 'not-an-id');
        $this->keeper()->graphById((string) $project->id, '0192d0ae-0000-7000-8000-000000000000');

        self::assertSame([], $this->events);
    }

    public function test_a_shared_experiment_is_kept_and_announced(): void
    {
        $project = $this->scenarioProject('keeper-experiment');
        $this->seedExperiment($project, 'trial');

        $result = $this->keeper()->experiment($project, 'trial', true);

        self::assertNotNull($result);
        self::assertNotNull($this->kept()->findForExperiment($project, 'trial'));
        self::assertCount(1, $this->events);
        self::assertSame('trial', $this->events[0]->experiment);
        self::assertNull($this->events[0]->graphId);
    }

    public function test_an_unknown_experiment_keeps_nothing(): void
    {
        $project = $this->scenarioProject('keeper-ghost');

        self::assertNull($this->keeper()->experiment($project, 'ghost', true));
        self::assertSame([], $this->events);
    }

    public function test_a_compute_whose_lock_ran_out_keeps_nothing_and_queues_the_graph_again(): void
    {
        $project = $this->scenarioProject('keeper-expired');
        $graph = $this->costGraph($project);
        $transport = $this->transport();
        $transport->reset();

        $expiring = new InsightsResultKeeper(
            new InsightsComputeLocks(new LockFactory(new ExpiringStore())),
            $this->kept(),
            $this->service(\App\Module\Insights\Service\KeptGraphComputer::class),
            $this->service(\App\Module\Workflow\Contract\TemplateMetrics::class),
            $this->service(\App\Module\Bridge\Command\ShowExperimentHandler::class),
            $this->service(\App\Module\Insights\Service\InsightsRefreshRequester::class),
            $this->service(\App\Module\Insights\Repository\InsightsSavedGraphRepository::class),
            $this->service(\App\Module\Insights\Service\SavedGraphHealth::class),
            $this->service(EventDispatcherInterface::class),
        );
        $expiring->graph($graph);

        self::assertNull($this->kept()->findForGraph($graph));
        self::assertSame([], $this->events);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshInsightsItem::class, $message);
        self::assertSame((string) $graph->id, $message->graphId);
    }

    private function costGraph(Project $project): InsightsSavedGraph
    {
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $graph->unit = MetricUnit::Run;
        $graph->statistic = MetricStatistic::Median;
        $graph->range = MetricRange::ThirtyDays;
        $graph->compare = MetricCompare::Previous;
        $this->em()->flush();

        return $graph;
    }

    private function keeper(): InsightsResultKeeper
    {
        return $this->service(InsightsResultKeeper::class);
    }

    private function kept(): InsightsKeptResultRepository
    {
        return $this->service(InsightsKeptResultRepository::class);
    }

    private function locks(): InsightsComputeLocks
    {
        return $this->service(InsightsComputeLocks::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
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

/** A store that grants the lock and refuses to extend it, like a lock whose time ran out and went to another compute. */
final class ExpiringStore implements PersistingStoreInterface
{
    private int $extensions = 0;

    public function save(Key $key): void
    {
    }

    public function delete(Key $key): void
    {
    }

    public function exists(Key $key): bool
    {
        return true;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // The first extension is the one that acquire() makes, and the next one is the refresh before the keep.
        if (++$this->extensions > 1) {
            throw new LockExpiredException('The lock ran out.');
        }
    }
}
