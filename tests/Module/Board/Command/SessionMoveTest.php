<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\OpenInteractiveRun;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Command\UpdateCardView;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardMover;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\CardHasWorker;
use App\Module\Bridge\Service\ClaimGate;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SessionMoveTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('session-move');
        $this->bindLifecycle($this->project);
    }

    public function test_a_session_move_records_the_arrival_and_opens_the_run(): void
    {
        $card = $this->card('backlog');

        $updated = $this->open($card);

        self::assertSame('in-progress', $card->column->slug);
        self::assertTrue($card->arrivedInSession);
        self::assertNotNull($updated->openedRun);
        $this->em()->clear();
        self::assertTrue($this->stored($card)->arrivedInSession);
    }

    public function test_a_run_that_moves_nothing_leaves_the_arrival_alone(): void
    {
        $card = $this->card('backlog');
        $this->open($card);

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Agent, openInteractiveRun: new OpenInteractiveRun(Uuid::v4(), 'second')));

        self::assertTrue($card->arrivedInSession);
    }

    public function test_a_run_opened_with_no_move_does_not_record_an_arrival(): void
    {
        $card = $this->card('in-progress');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Agent, openInteractiveRun: new OpenInteractiveRun(Uuid::v4(), 'session')));

        self::assertFalse($card->arrivedInSession);
    }

    public function test_the_next_move_of_the_card_clears_the_arrival(): void
    {
        $card = $this->card('backlog');
        $this->open($card);

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::System, column: $this->column($this->project, 'backlog')));

        self::assertFalse($card->arrivedInSession);
        $this->em()->clear();
        self::assertFalse($this->stored($card)->arrivedInSession);
    }

    public function test_a_rank_change_in_the_same_column_keeps_the_arrival(): void
    {
        $card = $this->card('backlog');
        $this->open($card);
        $neighbour = $this->card('in-progress');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Human, afterCardId: $neighbour->id?->toRfc4122()));

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame(1, $card->position);
        self::assertTrue($card->arrivedInSession);
        $this->em()->clear();
        self::assertTrue($this->stored($card)->arrivedInSession);
    }

    public function test_moving_a_whole_column_clears_the_arrival(): void
    {
        $card = $this->card('backlog');
        $this->open($card);
        $from = $this->column($this->project, 'in-progress');
        $to = $this->column($this->project, 'backlog');

        $mover = self::getContainer()->get(CardMover::class);
        self::assertInstanceOf(CardMover::class, $mover);
        $mover->moveAll($from, $to, new \DateTimeImmutable());
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $cards->refreshLoadedFrom($from);

        self::assertFalse($card->arrivedInSession);
        $this->em()->clear();
        self::assertFalse($this->stored($card)->arrivedInSession);
    }

    public function test_the_snapshot_carries_the_arrival(): void
    {
        $card = $this->card('backlog');
        self::assertFalse($card->snapshot()->arrivedInSession);

        $this->open($card);

        self::assertTrue($card->snapshot()->arrivedInSession);
    }

    public function test_an_open_worker_run_refuses_the_session_and_rolls_back_the_move(): void
    {
        $card = $this->card('backlog');
        $this->persistWorkerRun($card);

        try {
            $this->open($card);
            self::fail('A session may not open beside a worker.');
        } catch (CardHasWorker) {
        }

        $this->em()->clear();
        $stored = $this->stored($card);
        self::assertSame('backlog', $stored->column->slug);
        self::assertFalse($stored->arrivedInSession);
    }

    public function test_a_claimed_request_refuses_the_session(): void
    {
        $card = $this->card('in-progress');
        $this->persistRequest($card, null, WorkRequestState::Claimed);

        $this->expectException(CardHasWorker::class);
        $this->open($card);
    }

    public function test_a_claimed_session_request_does_not_refuse_the_session(): void
    {
        $card = $this->card('in-progress');
        $this->persistRequest($card, Bridge::CAPABILITY_SESSION, WorkRequestState::Claimed);

        self::assertNotNull($this->open($card)->openedRun);
    }

    public function test_an_open_request_does_not_refuse_the_session(): void
    {
        $card = $this->card('in-progress');
        $this->persistRequest($card, null, WorkRequestState::Open);

        self::assertNotNull($this->open($card)->openedRun);
    }

    public function test_the_same_session_opening_again_is_not_refused_by_its_own_run(): void
    {
        $card = $this->card('in-progress');
        $session = Uuid::v4();
        $first = $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Agent, openInteractiveRun: new OpenInteractiveRun($session, 'session')));
        $this->persistWorkerRun($card);

        $second = $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Agent, openInteractiveRun: new OpenInteractiveRun($session, 'session')));

        self::assertSame($first->openedRun?->id?->toRfc4122(), $second->openedRun?->id?->toRfc4122());
    }

    public function test_the_claim_gate_waits_while_a_session_is_open(): void
    {
        $card = $this->card('in-progress');
        $request = $this->persistRequest($card, null, WorkRequestState::Open);
        $gate = self::getContainer()->get(ClaimGate::class);
        self::assertInstanceOf(ClaimGate::class, $gate);
        $bridge = new \ReflectionClass(Bridge::class)->newInstanceWithoutConstructor();

        self::assertNull($gate->refusalFor($bridge, $request, null));

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Agent, openInteractiveRun: new OpenInteractiveRun(Uuid::v4(), 'session')));

        self::assertSame(WorkRequestRefusal::SessionOpen, $gate->refusalFor($bridge, $request, null));
    }

    public function test_the_claim_gate_lets_a_session_request_through_an_open_session(): void
    {
        $card = $this->card('in-progress');
        $request = $this->persistRequest($card, Bridge::CAPABILITY_SESSION, WorkRequestState::Open);
        $gate = self::getContainer()->get(ClaimGate::class);
        self::assertInstanceOf(ClaimGate::class, $gate);
        $bridge = new \ReflectionClass(Bridge::class)->newInstanceWithoutConstructor();
        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Agent, openInteractiveRun: new OpenInteractiveRun(Uuid::v4(), 'session')));

        self::assertNull($gate->refusalFor($bridge, $request, null));
    }

    private function open(Card $card): UpdateCardView
    {
        return $this->updateCard()(new UpdateCardCommand(
            card: $card,
            actor: Actor::Agent,
            column: $this->column($this->project, 'in-progress'),
            openInteractiveRun: new OpenInteractiveRun(Uuid::v4(), 'loupe:lifecycle-session'),
        ));
    }

    private function persistWorkerRun(Card $card): void
    {
        $this->em()->persist(new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Running,
        ));
        $this->em()->flush();
    }

    private function persistRequest(Card $card, ?string $capability, WorkRequestState $state): WorkRequest
    {
        $request = new WorkRequest($this->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('Stored.'), $card->number, 'implement', $capability, 'implement', new \DateTimeImmutable());
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function stored(Card $card): Card
    {
        $stored = $this->em()->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $stored);

        return $stored;
    }

    private function updateCard(): UpdateCardHandler
    {
        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);

        return $updateCard;
    }

    private function card(string $column): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, $column),
            reporter: Actor::Human,
        ));
    }
}
