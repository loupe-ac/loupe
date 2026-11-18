<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Workflow\Command\FullSweepWorkflowCardsCommand;
use App\Module\Workflow\Command\FullSweepWorkflowCardsHandler;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\WorkflowSweepRecord;
use App\Module\Workflow\Service\WorkflowSweepSettings;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\FeatureFlags;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FullSweepWorkflowCardsHandlerTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOW = '2026-11-17 12:00:00';

    public function test_a_full_sweep_queues_every_open_card_marked_as_full_and_records_its_time(): void
    {
        $this->setUpProject();

        $queued = $this->handler()(new FullSweepWorkflowCardsCommand());

        self::assertSame(1, $queued);
        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertTrue($sent[0]->fullSweep);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->service(WorkflowSweepRecord::class)->lastFullAt());
    }

    public function test_it_waits_until_the_last_full_sweep_is_older_than_the_flag_interval(): void
    {
        $this->setUpProject();
        $record = $this->service(WorkflowSweepRecord::class);

        $record->recordFull(new \DateTimeImmutable('2026-11-16 12:30:00'));
        self::assertSame(0, $this->handler()(new FullSweepWorkflowCardsCommand()));

        $record->recordFull(new \DateTimeImmutable('2026-11-16 11:59:00'));
        self::assertSame(1, $this->handler()(new FullSweepWorkflowCardsCommand()));
    }

    public function test_the_interval_comes_from_the_flag(): void
    {
        $this->setUpProject();
        $this->service(WorkflowSweepRecord::class)->recordFull(new \DateTimeImmutable('2026-11-17 06:00:00'));
        self::assertSame(0, $this->handler()(new FullSweepWorkflowCardsCommand()));

        $settings = new WorkflowSweepSettings(FeatureFlags::service([WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS => 6]));

        self::assertSame(6, $settings->fullSweepIntervalHours());
        self::assertSame(1, $this->handler($settings)(new FullSweepWorkflowCardsCommand()));
    }

    public function test_a_forced_sweep_ignores_the_age_of_the_last_one(): void
    {
        $this->setUpProject();
        $this->service(WorkflowSweepRecord::class)->recordFull(new \DateTimeImmutable('2026-11-17 11:59:00'));

        self::assertSame(1, $this->handler()(new FullSweepWorkflowCardsCommand(force: true)));
    }

    public function test_a_forced_sweep_queues_its_checks_while_the_queue_holds_messages(): void
    {
        $this->setUpProject();
        $this->handler()(new FullSweepWorkflowCardsCommand(force: true));
        self::assertCount(1, $this->sent());

        self::assertSame(1, $this->handler()(new FullSweepWorkflowCardsCommand(force: true)));
        self::assertCount(2, $this->sent());
    }

    public function test_a_project_mark_becomes_a_card_mark_before_the_checks_run(): void
    {
        $this->setUpProject();
        $connection = $this->service(\Doctrine\DBAL\Connection::class);
        $connection->executeStatement('DELETE FROM workflow_card_changes');
        $connection->executeStatement('DELETE FROM workflow_project_changes');
        $projectId = $connection->fetchOne('SELECT c.project_id FROM board_cards c JOIN workflow_bindings b ON b.project_id = c.project_id LIMIT 1');
        $connection->executeStatement('INSERT INTO workflow_project_changes (project_id, changed_at) VALUES (?, now())', [$projectId]);

        $this->handler()(new FullSweepWorkflowCardsCommand());

        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM workflow_card_changes'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM workflow_project_changes'));
    }

    private function setUpProject(): void
    {
        self::bootKernel();
        $this->service(WorkflowSweepRecord::class)->recordFull(new \DateTimeImmutable('2000-01-01'));
        $this->service(WorkflowSweepRecord::class)->recordBuild('x');
        $project = $this->workflowProject('full-sweep');
        $this->bindLifecycle($project);
        $this->card($project, 'next');
        $this->transport()->reset();
        $this->service(WorkflowSweepRecord::class)->recordFull(new \DateTimeImmutable('2000-01-01'));
    }

    private function handler(?WorkflowSweepSettings $settings = null): FullSweepWorkflowCardsHandler
    {
        return new FullSweepWorkflowCardsHandler(
            $this->service(WorkflowBindingRepository::class),
            $this->service(WorkflowCardChangeRepository::class),
            $this->service(EvaluationTrigger::class),
            $this->service(WorkflowSweepRecord::class),
            $settings ?? $this->service(WorkflowSweepSettings::class),
            new MockClock(self::NOW),
            $this->transport(),
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_sweep');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<EvaluateCard> */
    private function sent(): array
    {
        return array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard));
    }
}
