<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ReportSessionWakeCommand;
use App\Module\Bridge\Command\ReportSessionWakeHandler;
use App\Module\Bridge\ValueObject\WorkerRunWakeReport;
use App\Module\Bridge\ValueObject\WorkerRunWakeTrigger;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReportSessionWakeHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_filled_wake_asks_the_insights_worker_for_the_runs(): void
    {
        [$owner, $project, $sessionId, $transport] = $this->scenario('wake-filled');

        $result = $this->handler()(new ReportSessionWakeCommand($owner, (string) $project->id, $sessionId, [new WorkerRunWakeReport(WorkerRunWakeTrigger::Person)]));

        self::assertSame(1, $result->filled);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshProjectInsights::class, $message);
        self::assertSame('runs', $message->kind);
        self::assertSame((string) $project->id, $message->projectId);
    }

    public function test_a_report_that_fills_nothing_asks_for_no_refresh(): void
    {
        [$owner, $project, $sessionId, $transport] = $this->scenario('wake-unmatched');

        $result = $this->handler()(new ReportSessionWakeCommand($owner, (string) $project->id, $sessionId, [null, new WorkerRunWakeReport(WorkerRunWakeTrigger::Person)]));

        self::assertSame(0, $result->filled);
        self::assertSame([], $transport->getSent());
    }

    private function handler(): ReportSessionWakeHandler
    {
        $handler = self::getContainer()->get(ReportSessionWakeHandler::class);
        self::assertInstanceOf(ReportSessionWakeHandler::class, $handler);

        return $handler;
    }

    /** @return array{0: \App\Module\Account\Entity\User, 1: \App\Module\Project\Entity\Project, 2: \Symfony\Component\Uid\Uuid, 3: InMemoryTransport} */
    private function scenario(string $name): array
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, $name.'-'.uniqid().'@example.com');
        $project = $this->project($em, $owner, $name);
        $run = $this->seedRun($em, $project);
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        return [$owner, $project, $run->sessionId ?? throw new \LogicException('A worker run has a session.'), $transport];
    }
}
