<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Scheduler;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Inbox\Command\ReconcileBridgeOutagesCommand;
use App\Module\Inbox\Command\ReconcileBridgeOutagesHandler;
use App\Module\Inbox\Entity\InboxBridgeOutage;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxBridgeOutageRepository;
use App\Module\Inbox\Scheduler\ReconcileBridgeOutagesTask;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\ScheduledTasks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileBridgeOutagesTaskTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $waiting;
    private Project $quiet;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-10-16 12:00:00'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $owner = $this->owner($em, 'outage-task');
        $this->waiting = $this->project($em, $owner, 'outage-waiting');
        $this->quiet = $this->project($em, $owner, 'outage-quiet');
        $this->request($this->card($em, $this->waiting, 4));
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_the_task_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[ReconcileBridgeOutagesTask::class] ?? null,
        );
    }

    public function test_the_task_opens_the_notice_of_a_project_whose_work_waits(): void
    {
        $this->task()();

        $outages = $this->outages();
        self::assertCount(1, $outages);
        self::assertSame($this->waiting->id?->toRfc4122(), $outages[0]->project->id?->toRfc4122());
        self::assertSame(InboxItemState::Open, $outages[0]->item->state);
    }

    public function test_the_console_command_opens_the_notice_and_reports_the_count(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('The kernel is booted.'))->find('app:reconcile-bridge-outages'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Reconciled 1 project(s).', $tester->getDisplay());
        self::assertCount(1, $this->outages());
    }

    public function test_the_handler_visits_the_projects_with_open_card_work_and_the_projects_with_an_open_outage(): void
    {
        $handler = $this->handler();

        self::assertSame(1, $handler(new ReconcileBridgeOutagesCommand()));
        self::assertSame(1, $handler(new ReconcileBridgeOutagesCommand()));

        $this->request($this->card($this->em, $this->quiet, 5));
        $this->em->flush();

        self::assertSame(2, $handler(new ReconcileBridgeOutagesCommand()));
        self::assertCount(2, $this->outages());
    }

    public function test_while_the_inbox_is_off_only_the_open_outage_is_visited_and_it_closes(): void
    {
        $this->task()();
        $this->request($this->card($this->em, $this->quiet, 5));
        $this->em->flush();
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        self::assertSame(1, ($this->handler())(new ReconcileBridgeOutagesCommand()));

        $outages = $this->outages();
        self::assertCount(1, $outages);
        self::assertSame(InboxItemState::Cancelled, $outages[0]->item->state);
        self::assertNotNull($outages[0]->endedAt);
    }

    private function request(Card $card): void
    {
        $this->em->persist(new WorkRequest(
            project: $card->project,
            subjectType: 'card',
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            kind: 'implement',
            capability: null,
            ruleId: 'implement-on-entry',
            createdAt: new \DateTimeImmutable('2026-10-16 11:00:00'),
        ));
    }

    /** @return list<InboxBridgeOutage> */
    private function outages(): array
    {
        $repository = self::getContainer()->get(InboxBridgeOutageRepository::class);
        self::assertInstanceOf(InboxBridgeOutageRepository::class, $repository);

        return array_values($repository->findAll());
    }

    private function handler(): ReconcileBridgeOutagesHandler
    {
        $handler = self::getContainer()->get(ReconcileBridgeOutagesHandler::class);
        self::assertInstanceOf(ReconcileBridgeOutagesHandler::class, $handler);

        return $handler;
    }

    private function task(): ReconcileBridgeOutagesTask
    {
        $task = self::getContainer()->get(ReconcileBridgeOutagesTask::class);
        self::assertInstanceOf(ReconcileBridgeOutagesTask::class, $task);

        return $task;
    }
}
