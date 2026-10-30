<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Workflow\StopWork;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StopWorkTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_cancels_the_open_requests_stops_each_background_run_and_writes_one_stopped_row_for_each(): void
    {
        [$card, $bridge] = $this->card('stop-work');
        $project = $card->project;
        $cardId = $card->id;
        $implement = $this->seedWorkRequest($this->em(), $project, $cardId, 'implement', state: WorkRequestState::Claimed, bridgeId: $bridge->id, claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('+1 hour'));
        $fix = $this->seedWorkRequest($this->em(), $project, $cardId, 'fix');
        $person = $this->seedWorkRequest($this->em(), $project, $cardId, 'resume', ruleId: 'inbox:ask-answer');
        $worker = $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('-5 minutes'), workKind: 'implement', bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Running);
        $interactive = $this->seedRun($this->em(), $project, workKind: 'product-design', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $ended = $this->seedRun($this->em(), $project, cardId: $cardId, state: WorkerRunState::Succeeded);

        $outcome = $this->stop($card);

        self::assertEquals(ActionOutcome::done(), $outcome);
        $this->em()->flush();
        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $implement->id)?->state);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $fix->id)?->state);
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $person->id)?->state, 'A request that a person started is no work of a rule.');

        $commands = $this->service(BridgeCommandRepository::class)->findAll();
        self::assertCount(1, $commands);
        self::assertSame(BridgeCommandKind::StopRun, $commands[0]->kind);
        self::assertSame((string) $worker->id, (string) $commands[0]->workerRun->id);
        self::assertNull($commands[0]->requestedBy);
        self::assertSame('Stopped by the product-design-undo workflow rule.', $commands[0]->reason);

        $rows = $this->stoppedRows($card);
        self::assertCount(1, $rows);
        self::assertSame(Actor::System, $rows[0]->actorKind);
        self::assertSame((string) $worker->id, $rows[0]->detail['runId']);
        self::assertSame('implement', $rows[0]->detail['workKind']);
        self::assertSame('product-design-undo', $rows[0]->detail['rule']);
        self::assertIsInt($rows[0]->detail['durationSeconds']);
        self::assertNotContains((string) $interactive->id, array_column(array_map(static fn (CardEvent $row): array => $row->detail, $rows), 'runId'));
        self::assertNotContains((string) $ended->id, array_column(array_map(static fn (CardEvent $row): array => $row->detail, $rows), 'runId'));
    }

    public function test_it_leaves_an_open_background_run_that_a_person_started(): void
    {
        [$card, $bridge] = $this->card('stop-work-person');
        $project = $card->project;
        $cardId = $card->id;
        $personRequest = $this->seedWorkRequest($this->em(), $project, $cardId, 'resume', state: WorkRequestState::Claimed, ruleId: 'inbox:ask-answer');
        $byRequest = $this->seedRun($this->em(), $project, workKind: 'resume', cardId: $cardId, state: WorkerRunState::Running, workRequestId: $personRequest->id);
        $byCopy = $this->seedRun($this->em(), $project, workKind: 'resume', cardId: $cardId, state: WorkerRunState::Running, workRequestId: Uuid::v7(), ruleId: 'inbox:start-session');
        $byRule = $this->seedRun($this->em(), $project, workKind: 'implement', bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Running);

        self::assertEquals(ActionOutcome::done(), $this->stop($card));

        $stopped = array_map(static fn ($command): string => (string) $command->workerRun->id, $this->service(BridgeCommandRepository::class)->findAll());
        self::assertSame([(string) $byRule->id], $stopped);
        self::assertNotContains((string) $byRequest->id, $stopped);
        self::assertNotContains((string) $byCopy->id, $stopped);
    }

    public function test_it_takes_the_bridge_locks_in_bridge_order_before_it_locks_a_work_request(): void
    {
        [$card] = $this->card('stop-work-order');
        $project = $card->project;
        $second = $this->seedBridge($this->em(), $project->owner, Uuid::fromString('ffffffff-ffff-4fff-bfff-ffffffffffff'));
        $second->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $low = $this->seedBridge($this->em(), $project->owner, Uuid::fromString('00000000-0000-4000-8000-000000000001'));
        $low->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();
        $this->seedWorkRequest($this->em(), $project, $card->id, 'fix');
        $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('-2 hours'), workKind: 'implement', bridgeId: $low->id, cardId: $card->id, state: WorkerRunState::Running);
        $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('-1 hour'), workKind: 'implement', bridgeId: $second->id, cardId: $card->id, state: WorkerRunState::Running);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $this->stop($card);

        $locks = [];
        $withdraw = null;
        $position = 0;
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sql = (string) $query['sql'];
                if (str_contains($sql, 'pg_advisory_xact_lock')) {
                    $locks[] = ['position' => $position, 'bridge' => (string) ($query['params'][0] ?? '')];
                }
                if (null === $withdraw && str_contains($sql, 'work_requests') && (str_contains($sql, 'FOR UPDATE') || str_starts_with($sql, 'UPDATE'))) {
                    $withdraw = $position;
                }
                ++$position;
            }
        }
        self::assertCount(2, $locks);
        self::assertNotNull($withdraw, 'The action never locked or wrote the work request.');
        self::assertStringEndsWith((string) $low->id, $locks[0]['bridge']);
        self::assertStringEndsWith((string) $second->id, $locks[1]['bridge']);
        self::assertLessThan($withdraw, $locks[1]['position'], 'A work request must be locked after the bridge locks, the order of a claim.');
    }

    public function test_it_changes_nothing_for_a_run_that_has_a_stop_pending_and_for_a_card_with_no_work(): void
    {
        [$card, $bridge] = $this->card('stop-work-again');
        $this->seedRun($this->em(), $card->project, workKind: 'implement', bridgeId: $bridge->id, cardId: $card->id, state: WorkerRunState::Running);

        $this->stop($card);
        self::assertEquals(ActionOutcome::done(), $this->stop($card), 'A stop that is pending already is no refusal.');
        $this->em()->flush();

        self::assertSame(1, $this->countCommands($this->em()));
        self::assertCount(1, $this->stoppedRows($card));

        [$quiet] = $this->card('stop-work-quiet');
        self::assertEquals(ActionOutcome::done(), $this->stop($quiet));
        $this->em()->flush();
        self::assertSame([], $this->stoppedRows($quiet));
    }

    public function test_it_refuses_when_a_bridge_refuses_a_stop_after_it_stops_the_other_runs_and_cancels_the_requests(): void
    {
        [$card, $bridge] = $this->card('stop-work-refused');
        $project = $card->project;
        $outdated = $this->seedBridge($this->em(), $project->owner);
        $outdated->capabilities = [];
        $this->em()->flush();
        $fix = $this->seedWorkRequest($this->em(), $project, $card->id, 'fix');
        $this->seedRun($this->em(), $project, workKind: 'implement', bridgeId: $outdated->id, cardId: $card->id, state: WorkerRunState::Running);
        $worker = $this->seedRun($this->em(), $project, workKind: 'implement', bridgeId: $bridge->id, cardId: $card->id, state: WorkerRunState::Running);
        $this->startLater($worker, 1);
        $this->seedRun($this->em(), $project, workKind: 'implement', bridgeId: $bridge->id, cardId: $card->id, state: WorkerRunState::Stopping);

        self::assertEquals(ActionOutcome::refused(StopWork::STOP_REFUSED), $this->stop($card));
        $this->em()->flush();
        $this->em()->clear();

        $commands = $this->service(BridgeCommandRepository::class)->findAll();
        self::assertCount(1, $commands);
        self::assertSame((string) $worker->id, (string) $commands[0]->workerRun->id);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $fix->id)?->state);
    }

    public function test_it_refuses_when_the_card_has_more_open_runs_than_one_pass_reads(): void
    {
        [$card, $bridge] = $this->card('stop-work-many');
        for ($i = 1; $i <= 101; ++$i) {
            $run = $this->seedRun($this->em(), $card->project, workKind: 'implement', bridgeId: $bridge->id, cardId: $card->id, state: WorkerRunState::Running);
            $this->startLater($run, $i);
        }

        self::assertEquals(ActionOutcome::refused(StopWork::STOP_REFUSED), $this->stop($card));
        $this->em()->flush();

        self::assertSame(100, $this->countCommands($this->em()));
    }

    public function test_interactive_sessions_do_not_count_toward_the_limit_of_one_pass(): void
    {
        [$card, $bridge] = $this->card('stop-work-interactive-many');
        for ($i = 1; $i <= 101; ++$i) {
            $run = $this->seedRun($this->em(), $card->project, workKind: 'product-design', bridgeId: $bridge->id, cardId: $card->id, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
            $this->startLater($run, $i);
        }
        $this->seedRun($this->em(), $card->project, workKind: 'implement', bridgeId: $bridge->id, cardId: $card->id, state: WorkerRunState::Running);

        self::assertEquals(ActionOutcome::done(), $this->stop($card));
        $this->em()->flush();

        self::assertSame(1, $this->countCommands($this->em()));
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

    /** Two runs of one bridge and card need distinct start times, which the seed fixes. */
    private function startLater(WorkerRun $run, int $seconds): void
    {
        $run->startedAt = new \DateTimeImmutable(\sprintf('2026-01-01 10:00:00 +%d seconds', $seconds));
        $this->em()->flush();
    }

    /** @return array{Card, Bridge} */
    private function card(string $name): array
    {
        self::bootKernel();
        $owner = $this->user($this->em(), $name.'@example.com');
        $project = $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8));
        $column = new BoardColumn($project, 'Implementation', 'implementation', 0);
        $this->em()->persist($column);
        $card = new Card($project, $column, 'Card', '', 7);
        $this->em()->persist($card);
        $this->em()->flush();
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        return [$card, $bridge];
    }

    private function stop(Card $card): ActionOutcome
    {
        $action = self::getContainer()->get(StopWork::class);
        self::assertInstanceOf(StopWork::class, $action);

        return $action->run(new ActionContext($card->snapshot(), 'product-design-undo', false, [], FactsMother::facts(), 0));
    }

    /** @return list<CardEvent> */
    private function stoppedRows(Card $card): array
    {
        $repository = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $repository);

        return array_values(array_filter($repository->findForCard($card), static fn (CardEvent $event): bool => CardEventKind::Stopped === $event->kind));
    }
}
