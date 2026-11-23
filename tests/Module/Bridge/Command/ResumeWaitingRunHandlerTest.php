<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ResumeWaitingRunCommand;
use App\Module\Bridge\Command\ResumeWaitingRunHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ResumeWaitingRunHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_an_ended_wait_resumes_its_parked_run_with_the_met_conditions(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Waiting);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::Condition, [['key' => 'pr.checks_failed', 'params' => [], 'pullRequestId' => null, 'token' => 'pr:o/r#12']]);

        $this->resume($wait);

        $commands = $this->commands();
        self::assertCount(1, $commands);
        self::assertSame((string) $run->id, (string) $commands[0]->workerRun?->id);
        self::assertSame(BridgeCommandKind::ResumeRun, $commands[0]->kind);
        self::assertNull($commands[0]->requestedBy);
        self::assertSame(BridgeCommandCause::WaitEnded, $commands[0]->cause);
        $payload = BridgeCommandPayload::of($commands[0]);
        self::assertSame('wait-ended', $payload['cause']);
        self::assertSame(['reason' => 'condition', 'conditions' => [['key' => 'pr.checks_failed', 'pullRequest' => 'pr:o/r#12']]], $payload['wait']);
    }

    public function test_a_time_limit_resumes_with_no_conditions(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-limit');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Waiting);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::TimedOut);

        $this->resume($wait);

        self::assertSame(['reason' => 'timed-out', 'conditions' => []], BridgeCommandPayload::of($this->commands()[0])['wait']);
    }

    public function test_a_run_that_still_runs_gets_no_resume(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-live');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Running);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::Condition);

        $this->resume($wait);

        self::assertSame([], $this->commands());
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function finishedStates(): iterable
    {
        yield 'blocked' => [WorkerRunState::Blocked];
        yield 'succeeded' => [WorkerRunState::Succeeded];
    }

    #[DataProvider('finishedStates')]
    public function test_a_run_that_finished_after_its_wait_ended_gets_no_resume(WorkerRunState $state): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-'.$state->value);
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: $state);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::Condition);

        $this->resume($wait);

        self::assertSame([], $this->commands());
    }

    public function test_a_wait_that_a_run_resumed_gets_no_second_resume(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-twice');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Waiting);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::Condition);
        $wait->resumeRunId = Uuid::v7();
        $this->em()->flush();

        $this->resume($wait);

        self::assertSame([], $this->commands());
    }

    public function test_a_second_request_for_the_same_end_adds_no_command(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-repeat');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Waiting);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::Condition);

        $this->resume($wait);
        $this->resume($wait);

        self::assertCount(1, $this->commands());
    }

    public function test_an_end_that_stops_the_run_resumes_nothing(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-cancel');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Waiting);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::CancelledByPerson);

        $this->resume($wait);

        self::assertSame([], $this->commands());
    }

    public function test_a_bridge_that_takes_no_commands_leaves_the_wait_ended_with_no_resume(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('wait-resume-old-bridge', capabilities: []);
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Waiting);
        $wait = $this->endedWait($project, $run, WorkerWaitEndReason::Condition);

        $this->resume($wait);

        self::assertSame([], $this->commands());
        $this->em()->clear();
        self::assertNull($this->em()->find(WorkerWait::class, $wait->id)?->resumeRunId);
    }

    /**
     * @param list<string> $capabilities
     *
     * @return array{Project, Bridge}
     */
    private function scenario(string $name, array $capabilities = [Bridge::CAPABILITY_COMMANDS]): array
    {
        $owner = $this->user($this->em(), $name.'@example.com');
        $project = $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = $capabilities;
        $this->em()->flush();

        return [$project, $bridge];
    }

    /** @param list<array{key: string, params: array<string, mixed>, pullRequestId: ?string, token: ?string}> $met */
    private function endedWait(Project $project, WorkerRun $run, WorkerWaitEndReason $reason, array $met = []): WorkerWait
    {
        $opened = new \DateTimeImmutable('2026-10-01 10:00:00');
        $wait = new WorkerWait($project, $run->subjectId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, $run->bridgeId ?? throw new \LogicException('Seeded.'), $run->sessionId ?? throw new \LogicException('Seeded.'), [], $opened, $opened->modify('+1 hour'));
        $wait->end($reason, $opened->modify('+5 minutes'), $met);
        $this->em()->persist($wait);
        $this->em()->flush();

        return $wait;
    }

    private function resume(WorkerWait $wait): void
    {
        $handler = self::getContainer()->get(ResumeWaitingRunHandler::class);
        self::assertInstanceOf(ResumeWaitingRunHandler::class, $handler);
        $handler(new ResumeWaitingRunCommand($wait->id ?? throw new \LogicException('Stored.')));
    }

    /** @return list<BridgeCommand> */
    private function commands(): array
    {
        $this->em()->clear();

        $repository = self::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);

        return array_values($repository->findAll());
    }
}
