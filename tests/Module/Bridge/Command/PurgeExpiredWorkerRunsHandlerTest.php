<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\PurgeExpiredWorkerRunsCommand;
use App\Module\Bridge\Command\PurgeExpiredWorkerRunsHandler;
use App\Module\Bridge\Entity\ExperimentArchive;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class PurgeExpiredWorkerRunsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-13 12:00:00';

    public function test_it_deletes_a_run_past_the_window_and_keeps_one_inside_it(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-sweep@example.com'), 'Swept Runs');
        $expired = $this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $fresh = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 00:00:00'), cardNumber: 2);

        $purged = ($this->handler())(new PurgeExpiredWorkerRunsCommand());

        self::assertSame(1, $purged);
        $em->clear();
        self::assertNull($em->find(WorkerRun::class, $expired->id));
        self::assertNotNull($em->find(WorkerRun::class, $fresh->id));
    }

    /** The sweep deletes runs in bulk, so only the foreign key can take their commands. */
    public function test_it_takes_the_commands_of_a_deleted_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'commands-sweep@example.com'), 'Swept Commands');
        $this->seedCommand($em, $this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 00:00:00')));
        $kept = $this->seedCommand($em, $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 00:00:00'), cardNumber: 2));

        self::assertSame(1, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        self::assertSame([(string) $kept->id], $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_commands'));
    }

    public function test_it_takes_the_tool_calls_of_a_deleted_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'tool-calls-sweep@example.com'), 'Swept Tool Calls');
        $this->seedToolCall($this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 00:00:00')));
        $kept = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 00:00:00'), cardNumber: 2);
        $this->seedToolCall($kept);

        self::assertSame(1, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        self::assertSame([(string) $kept->id], $em->getConnection()->fetchFirstColumn('SELECT run_id FROM bridge_worker_run_tool_calls'));
    }

    /** A sample past the window goes, and the count still names the runs alone. */
    public function test_it_deletes_a_host_sample_past_the_window_and_keeps_one_inside_it(): void
    {
        self::bootKernel();
        $em = $this->em();
        $bridge = $this->seedBridge($em, $this->user($em, 'samples-sweep@example.com'));
        $this->seedHostSample($bridge, '2026-01-01 00:00:00');
        $this->seedHostSample($bridge, '2026-09-01 00:00:00');

        self::assertSame(0, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        self::assertSame(['2026-09-01 00:00:00'], $em->getConnection()->fetchFirstColumn('SELECT sampled_at FROM bridge_host_samples'));
    }

    /** A pin that no run refreshed within the window goes, and the count still names the runs alone. */
    public function test_it_deletes_a_pin_past_the_window_and_keeps_one_inside_it(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'pins-sweep@example.com'), 'Swept Pins');
        $expired = new ExperimentPin($project, Uuid::v7(), 'plan-model', 'opus', updatedAt: new \DateTimeImmutable('2026-01-01 00:00:00'));
        $fresh = new ExperimentPin($project, Uuid::v7(), 'plan-model', 'sonnet', updatedAt: new \DateTimeImmutable('2026-09-01 00:00:00'));
        $em->persist($expired);
        $em->persist($fresh);
        $em->flush();

        self::assertSame(0, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        $em->clear();
        self::assertNull($em->find(ExperimentPin::class, $expired->id));
        self::assertNotNull($em->find(ExperimentPin::class, $fresh->id));
    }

    public function test_an_unarchive_by_a_new_run_outlives_that_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'archive-sweep-back@example.com'), 'Swept Archive Back');
        $this->namedRun($project, 'trial', '2026-01-02 00:00:00', 1);
        $em->persist(new ExperimentPin($project, Uuid::v7(), 'trial', 'a', updatedAt: new \DateTimeImmutable('2026-09-01 00:00:00')));
        $em->persist(new ExperimentArchive($project, 'trial', new \DateTimeImmutable('2026-01-01 00:00:00'), []));
        $em->flush();

        ($this->handler())(new PurgeExpiredWorkerRunsCommand());

        $em->clear();
        self::assertSame([], $this->archiveRows());
    }

    public function test_it_keeps_an_archive_row_whose_runs_are_all_covered(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'archive-sweep-keep@example.com'), 'Swept Archive Keep');
        $run = $this->namedRun($project, 'trial', '2026-09-01 00:00:00', 1);
        $em->persist(new ExperimentArchive($project, 'trial', new \DateTimeImmutable('2026-09-01 00:00:00'), [(string) $run->id]));
        $em->flush();

        ($this->handler())(new PurgeExpiredWorkerRunsCommand());

        $em->clear();
        self::assertCount(1, $this->archiveRows());
    }

    public function test_it_deletes_an_archive_row_when_the_last_run_and_pin_are_gone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'archive-sweep-gone@example.com'), 'Swept Archive Gone');
        $run = $this->namedRun($project, 'trial', '2026-01-02 00:00:00', 1);
        $em->persist(new ExperimentArchive($project, 'trial', new \DateTimeImmutable('2026-01-03 00:00:00'), [(string) $run->id]));
        $em->flush();

        ($this->handler())(new PurgeExpiredWorkerRunsCommand());

        $em->clear();
        self::assertSame([], $this->archiveRows());
    }

    /** An Archive action between the unlocked read and the locked delete keeps its row. */
    public function test_an_archive_between_the_read_and_the_delete_stays(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'archive-sweep-race@example.com'), 'Swept Archive Race');
        $run = $this->namedRun($project, 'trial', '2026-09-01 00:00:00', 1);
        $em->persist(new ExperimentArchive($project, 'trial', new \DateTimeImmutable('2026-09-01 00:00:00'), []));
        $em->persist(new ExperimentPin($project, Uuid::v7(), 'trial', 'a', updatedAt: new \DateTimeImmutable('2026-09-01 00:00:00')));
        $em->flush();

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $runId = (string) $run->id;
        $archives = new class($registry, $runId) extends ExperimentArchiveRepository {
            public function __construct(
                ManagerRegistry $registry,
                private readonly string $runId,
            ) {
                parent::__construct($registry);
            }

            #[\Override]
            public function findOneByName(Project $project, string $experiment): ?ExperimentArchive
            {
                // The Archive action that lands after the sweep read its candidates.
                $this->getEntityManager()->getConnection()->executeStatement(
                    'UPDATE bridge_experiment_archives SET covered_run_ids = :ids',
                    ['ids' => json_encode([$this->runId], \JSON_THROW_ON_ERROR)],
                );

                return parent::findOneByName($project, $experiment);
            }
        };

        ($this->handler($archives))(new PurgeExpiredWorkerRunsCommand());

        $em->clear();
        self::assertCount(1, $this->archiveRows());
    }

    /** @return list<ExperimentArchive> */
    private function archiveRows(): array
    {
        $archives = self::getContainer()->get(ExperimentArchiveRepository::class);
        self::assertInstanceOf(ExperimentArchiveRepository::class, $archives);

        return $archives->findAllRows();
    }

    private function namedRun(Project $project, string $experiment, string $receivedAt, int $cardNumber): WorkerRun
    {
        $run = $this->seedRun($this->em(), $project, new \DateTimeImmutable($receivedAt), cardNumber: $cardNumber);
        $run->experiment = $experiment;
        $run->variant = 'a';
        $this->em()->flush();

        return $run;
    }

    /** The spend of a card outlives the retention window of its runs. */
    public function test_it_keeps_the_usage_of_a_deleted_run_unlinked(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-sweep@example.com'), 'Swept Usage');
        $expired = $this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $usageId = $this->seedUsage($em, $expired)->id;

        self::assertSame(1, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        $em->clear();
        self::assertNull($em->find(WorkerRun::class, $expired->id));
        self::assertSame(
            [['id' => (string) $usageId, 'run_id' => null, 'project_id' => (string) $project->id, 'source' => 'reported']],
            $em->getConnection()->fetchAllAssociative('SELECT id, run_id, project_id, source FROM bridge_worker_run_usage'),
        );
    }

    /** The sweep reads the flag, so an operator who shortens the window sees it apply on the next tick. */
    public function test_the_flag_moves_the_cut(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-sweep-flag@example.com'), 'Flagged Sweep');
        $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertSame(0, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        $this->setRetentionDays(7);

        self::assertSame(1, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));
        $em->clear();
        self::assertNull($em->find(WorkerRun::class, $run->id));
    }

    /**
     * A window of zero would take every run on the first tick, so the policy
     * floors it at one day. The fixture sits inside that one day and outside a
     * zero-day window, which is what tells the two apart.
     */
    public function test_a_window_of_zero_reads_as_one_day(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-sweep-zero@example.com'), 'Zero Sweep');
        $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-13 00:00:00'));
        $this->setRetentionDays(0);

        self::assertSame(0, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));
        $em->clear();
        self::assertNotNull($em->find(WorkerRun::class, $run->id));
    }

    private function setRetentionDays(int $days): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[WorkerRunRetentionPolicy::FLAG]->value = $days;
        $this->em()->flush();

        // The reader caches every flag for the life of the request, and a test
        // is one request, so a write alone would not reach the policy.
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    private function handler(?ExperimentArchiveRepository $archives = null): PurgeExpiredWorkerRunsHandler
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $flags = self::getContainer()->get(FeatureFlagService::class);
        self::assertInstanceOf(FeatureFlagService::class, $flags);

        return new PurgeExpiredWorkerRunsHandler(
            new WorkerRunRepository($registry),
            new ExperimentPinRepository($registry),
            $archives ?? new ExperimentArchiveRepository($registry),
            new BridgeHostSampleRepository($registry),
            new WorkerRunRetentionPolicy($flags, 180),
            new MockClock(new \DateTimeImmutable(self::NOW)),
            $this->em(),
        );
    }
}
