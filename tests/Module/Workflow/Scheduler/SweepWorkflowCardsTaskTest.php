<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Scheduler;

use App\Module\Bridge\Entity\CardHold;
use App\Module\Workflow\Command\SweepWorkflowCardsHandler;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use App\Module\Workflow\Scheduler\SweepWorkflowCardsTask;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\WorkflowSweepMetrics;
use App\Module\Workflow\Service\WorkflowSweepRecord;
use App\Module\Workflow\Service\WorkflowSweepSettings;
use App\Service\BuildIdentity;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ScheduledTasks;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class SweepWorkflowCardsTaskTest extends KernelTestCase
{
    use ActionScenario;

    private ?string $projectDir = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->projectDir) {
            @unlink($this->projectDir.'/var/build-version');
            @rmdir($this->projectDir.'/var');
            @rmdir($this->projectDir);
            $this->projectDir = null;
        }
        parent::tearDown();
    }

    public function test_the_task_is_registered_on_the_default_schedule_every_ten_minutes(): void
    {
        self::bootKernel();

        self::assertSame('*/10 * * * *', ScheduledTasks::cronExpressions(self::getContainer())[SweepWorkflowCardsTask::class] ?? null);
    }

    public function test_the_sweep_queues_the_open_cards_of_bound_projects_held_ones_too_and_logs_the_count(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-bound');
        $this->bindLifecycle($bound);
        $open = $this->card($bound, 'next');
        $backlog = $this->card($bound, 'backlog');
        $finished = $this->card($bound, 'done');
        $held = $this->card($bound, 'next');
        $this->em()->persist(new CardHold($bound, $held->id ?? throw new \LogicException('A flushed card has an id.'), null, new \DateTimeImmutable()));
        $this->em()->flush();
        $unbound = $this->card($this->workflowProject('sweep-unbound'), 'next');
        $this->transport()->reset();
        $logger = new RecordingLogger();

        $this->task($logger)();

        $sent = $this->sent();
        self::assertContains((string) $open->id, $sent);
        self::assertContains((string) $backlog->id, $sent);
        self::assertNotContains((string) $finished->id, $sent);
        self::assertContains((string) $held->id, $sent);
        self::assertNotContains((string) $unbound->id, $sent);
        self::assertCount(1, $logger->records);
        self::assertSame('workflow.sweep_finished', $logger->records[0]['message']);
        self::assertSame(\count($sent), $logger->records[0]['context']['cards']);
        self::assertIsInt($logger->records[0]['context']['durationMs']);
    }

    public function test_the_sweep_queues_on_the_low_priority_transport_only(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-low');
        $this->bindLifecycle($bound);
        $this->card($bound, 'next');
        $jobs = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $jobs);
        $cardEval = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $cardEval);
        $jobs->reset();
        $cardEval->reset();
        $this->transport()->reset();

        $this->task(new RecordingLogger())();

        self::assertNotSame([], $this->sent());
        self::assertSame([], $jobs->getSent());
        self::assertSame([], $cardEval->getSent());
    }

    public function test_the_sweep_queues_nothing_while_the_previous_sweep_still_waits(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-skip');
        $this->bindLifecycle($bound);
        $this->card($bound, 'next');
        $this->transport()->reset();
        $logger = new RecordingLogger();

        $this->task($logger, waiting: 3)();

        self::assertSame([], $this->sent());
        self::assertSame(0, $logger->records[0]['context']['cards']);
    }

    public function test_the_first_sweep_under_a_new_build_is_the_deploy_pass_and_the_next_is_not(): void
    {
        self::bootKernel();
        $this->service(WorkflowSweepRecord::class)->recordBuild('old-build');
        $this->buildVersion('new-build');
        $bound = $this->workflowProject('sweep-deploy');
        $this->bindLifecycle($bound);
        $this->card($bound, 'next');
        $this->transport()->reset();

        $this->task(new RecordingLogger())();
        $first = $this->sentMessages();
        $this->transport()->reset();
        $this->task(new RecordingLogger())();
        $second = $this->sentMessages();

        self::assertNotSame([], $first);
        self::assertContainsOnlyInstancesOf(EvaluateCard::class, $first);
        self::assertSame([true], array_values(array_unique(array_map(static fn (EvaluateCard $message): bool => $message->fullSweep, $first))));
        self::assertSame([false], array_values(array_unique(array_map(static fn (EvaluateCard $message): bool => $message->fullSweep, $second))));
        self::assertSame('new-build', $this->service(WorkflowSweepRecord::class)->build());
        self::assertNotNull($this->service(WorkflowSweepRecord::class)->lastFullAt());
    }

    public function test_a_build_with_no_version_file_never_runs_the_deploy_pass(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-noversion');
        $this->bindLifecycle($bound);
        $this->card($bound, 'next');
        $this->transport()->reset();

        $this->task(new RecordingLogger())();

        self::assertSame([false], array_values(array_unique(array_map(static fn (EvaluateCard $message): bool => $message->fullSweep, $this->sentMessages()))));
    }

    public function test_with_the_flag_on_the_sweep_queues_only_the_marked_open_bound_cards(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-marked');
        $this->bindLifecycle($bound);
        $marked = $this->card($bound, 'next');
        $quiet = $this->card($bound, 'next');
        $finished = $this->card($bound, 'done');
        $unbound = $this->card($this->workflowProject('sweep-marked-unbound'), 'next');
        $this->clearMarks();
        $this->mark($marked, $finished, $unbound);
        $this->transport()->reset();

        $this->task(new RecordingLogger(), changeDriven: true)();

        self::assertSame([(string) $marked->id], $this->sent());
        self::assertNotContains((string) $quiet->id, $this->sent());
        $stale = $this->service(WorkflowCardChangeRepository::class);
        self::assertFalse($stale->isMarked($finished->id ?? throw new \LogicException('Flushed.')));
        self::assertFalse($stale->isMarked($unbound->id ?? throw new \LogicException('Flushed.')));
        self::assertTrue($stale->isMarked($marked->id ?? throw new \LogicException('Flushed.')));
    }

    public function test_with_the_flag_on_a_project_mark_marks_each_open_card_of_the_project_and_goes(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-project-mark');
        $this->bindLifecycle($bound);
        $one = $this->card($bound, 'next');
        $two = $this->card($bound, 'backlog');
        $finished = $this->card($bound, 'done');
        $otherProject = $this->workflowProject('sweep-project-other');
        $this->bindLifecycle($otherProject);
        $other = $this->card($otherProject, 'next');
        $this->clearMarks();
        $this->connection()->executeStatement('INSERT INTO workflow_project_changes (project_id, changed_at) VALUES (?, now())', [(string) $bound->id]);
        $this->transport()->reset();

        $this->task(new RecordingLogger(), changeDriven: true)();

        $sent = $this->sent();
        sort($sent);
        $expected = [(string) $one->id, (string) $two->id];
        sort($expected);
        self::assertSame($expected, $sent);
        self::assertNotContains((string) $finished->id, $sent);
        self::assertNotContains((string) $other->id, $sent);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM workflow_project_changes'));
    }

    public function test_with_the_flag_on_the_open_children_of_an_epic_are_always_queued(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-outside');
        $this->bindLifecycle($bound);
        $epic = $this->card($bound, 'next');
        $child = $this->card($bound, 'next');
        $child->parent = $epic;
        $this->em()->flush();
        $quiet = $this->card($bound, 'next');
        $this->clearMarks();
        $this->transport()->reset();

        $this->task(new RecordingLogger(), changeDriven: true)();

        self::assertSame([(string) $child->id], $this->sent());
        self::assertNotContains((string) $quiet->id, $this->sent());
    }

    public function test_with_the_flag_on_the_deploy_pass_still_queues_every_open_card(): void
    {
        self::bootKernel();
        $this->service(WorkflowSweepRecord::class)->recordBuild('old-build');
        $this->buildVersion('new-build');
        $bound = $this->workflowProject('sweep-deploy-marked');
        $this->bindLifecycle($bound);
        $quiet = $this->card($bound, 'next');
        $this->clearMarks();
        $this->transport()->reset();

        $this->task(new RecordingLogger(), changeDriven: true)();

        self::assertContains((string) $quiet->id, $this->sent());
    }

    public function test_with_the_flag_off_the_sweep_queues_every_open_card_whatever_the_marks(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-flag-off');
        $this->bindLifecycle($bound);
        $quiet = $this->card($bound, 'next');
        $this->clearMarks();
        $this->transport()->reset();

        $this->task(new RecordingLogger(), changeDriven: false)();

        self::assertContains((string) $quiet->id, $this->sent());
    }

    private function clearMarks(): void
    {
        $this->connection()->executeStatement('DELETE FROM workflow_card_changes');
        $this->connection()->executeStatement('DELETE FROM workflow_project_changes');
    }

    private function mark(\App\Module\Board\Entity\Card ...$cards): void
    {
        foreach ($cards as $card) {
            $this->connection()->executeStatement('INSERT INTO workflow_card_changes (card_id, changed_at) VALUES (?, now())', [(string) $card->id]);
        }
    }

    private function connection(): Connection
    {
        return $this->service(Connection::class);
    }

    private function buildVersion(string $version): void
    {
        $this->projectDir = sys_get_temp_dir().'/loupe-build-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/var', 0o777, true);
        file_put_contents($this->projectDir.'/var/build-version', $version);
    }

    /** @return list<EvaluateCard> */
    private function sentMessages(): array
    {
        return array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard));
    }

    private function task(RecordingLogger $logger, ?int $waiting = null, bool $changeDriven = false): SweepWorkflowCardsTask
    {
        $transport = null === $waiting ? $this->transport() : new readonly class($waiting) implements TransportInterface, MessageCountAwareInterface {
            public function __construct(
                private int $waiting,
            ) {
            }

            public function getMessageCount(): int
            {
                return $this->waiting;
            }

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }
        };

        return new SweepWorkflowCardsTask(
            new SweepWorkflowCardsHandler(
                $this->service(WorkflowBindingRepository::class),
                $this->service(WorkflowCardChangeRepository::class),
                $this->service(FactProviders::class),
                new WorkflowSweepSettings(FeatureFlags::service([WorkflowInstallFlags::FLAG_CHANGE_DRIVEN_SWEEP => $changeDriven])),
                $this->service(EvaluationTrigger::class),
                $this->service(WorkflowSweepRecord::class),
                new BuildIdentity($this->projectDir ?? '/nonexistent'),
                new MockClock('2026-11-17 04:00:00'),
                $transport,
            ),
            $this->service(WorkflowSweepMetrics::class),
            $logger,
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_sweep');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> */
    private function sent(): array
    {
        return array_values(array_map(
            static fn (EvaluateCard $message): string => $message->cardId,
            array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard),
        ));
    }
}
