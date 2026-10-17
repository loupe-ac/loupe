<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\BridgeCommandSettled;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ClearRunAskOnResumeFailedTest extends KernelTestCase
{
    use BridgeScenario;

    /** @return iterable<string, array{BridgeCommandState, BridgeCommandKind, BridgeCommandCause, bool}> */
    public static function settledCommands(): iterable
    {
        yield 'a refused resume after an answer' => [BridgeCommandState::Refused, BridgeCommandKind::ResumeRun, BridgeCommandCause::AskClosed, true];
        yield 'an expired resume after an answer' => [BridgeCommandState::Expired, BridgeCommandKind::ResumeRun, BridgeCommandCause::AskClosed, true];
        yield 'a cancelled resume after an answer' => [BridgeCommandState::Cancelled, BridgeCommandKind::ResumeRun, BridgeCommandCause::AskClosed, true];
        yield 'a resume the bridge did' => [BridgeCommandState::Done, BridgeCommandKind::ResumeRun, BridgeCommandCause::AskClosed, false];
        yield 'a refused resume a person asked for' => [BridgeCommandState::Refused, BridgeCommandKind::ResumeRun, BridgeCommandCause::Person, false];
        yield 'a refused stop' => [BridgeCommandState::Refused, BridgeCommandKind::StopRun, BridgeCommandCause::AskClosed, false];
    }

    #[DataProvider('settledCommands')]
    public function test_only_a_failed_resume_after_an_answer_removes_the_ask_from_the_run(BridgeCommandState $state, BridgeCommandKind $kind, BridgeCommandCause $cause, bool $cleared): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'clear-ask@example.com');
        $project = $this->project($this->em(), $owner, 'Clear ask');
        $bridge = $this->seedBridge($this->em(), $owner);
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Blocked);
        $askId = Uuid::v7();
        $run->waitsOnAskId = $askId;
        $command = $this->seedCommand($this->em(), $run, $state, kind: $kind);
        $command->cause = $cause;
        $this->em()->flush();
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        $events->dispatch(BridgeCommandSettled::of($command));

        $this->em()->clear();
        $stored = $this->em()->find(WorkerRun::class, $run->id)?->waitsOnAskId;
        if ($cleared) {
            self::assertNull($stored);
        } else {
            self::assertEquals($askId, $stored);
        }
    }
}
