<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Service\InitInstallWaits;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ClearInitInstallWaitOnWorkerRunChangedTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_closed_init_run_ends_its_wait_and_an_open_one_keeps_it(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'clear-wait@example.com');
        $project = $this->project($this->em(), $owner, 'Clear wait');
        $waits = self::getContainer()->get(InitInstallWaits::class);
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(InitInstallWaits::class, $waits);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        $open = $this->seedRun($this->em(), $project, workKind: 'init', bridgeId: Uuid::v7(), state: WorkerRunState::Running, kind: WorkerRunKind::Interactive, subjectType: WorkSubject::PROJECT);
        $closed = $this->seedRun($this->em(), $project, workKind: 'init', bridgeId: Uuid::v7(), state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive, subjectType: WorkSubject::PROJECT);
        $open->sessionId = Uuid::v7();
        $closed->sessionId = Uuid::v7();
        $this->em()->flush();
        $projectId = (string) $project->id;
        $waits->mark($open->sessionId->toRfc4122(), $projectId);
        $waits->mark($closed->sessionId->toRfc4122(), $projectId);

        foreach (WorkerRunChanged::ofRuns([$open, $closed]) as $event) {
            $events->dispatch($event);
        }

        self::assertTrue($waits->isWaiting($open->sessionId->toRfc4122(), $projectId));
        self::assertFalse($waits->isWaiting($closed->sessionId->toRfc4122(), $projectId));
    }
}
