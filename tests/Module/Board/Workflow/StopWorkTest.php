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
        $this->stop($card);
        $this->em()->flush();

        self::assertSame(1, $this->countCommands($this->em()));
        self::assertCount(1, $this->stoppedRows($card));

        [$quiet] = $this->card('stop-work-quiet');
        self::assertEquals(ActionOutcome::done(), $this->stop($quiet));
        $this->em()->flush();
        self::assertSame([], $this->stoppedRows($quiet));
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
