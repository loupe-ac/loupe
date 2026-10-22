<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** The engine runs the marker pass after the rule pass, for held cards and boards with the automation off too. */
final class MarkerPassFlowTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOON = '2026-10-02 12:00:00';

    /** @var list<CardChanged> */
    private array $changes = [];

    public function test_a_rule_that_fires_shows_in_the_markers_of_the_same_pass(): void
    {
        $card = $this->boundCard();

        $this->evaluate($card);

        self::assertSame(['work-requested'], $this->keys($card));
    }

    public function test_a_held_card_gets_its_markers_and_no_rule_runs(): void
    {
        $card = $this->boundCard();
        $this->hold($card);

        $this->evaluate($card);

        self::assertSame(['unmanaged'], $this->keys($card));
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_pass_on_a_held_card_leaves_its_work_requests_alone(): void
    {
        $card = $this->boundCard();
        $this->hold($card);
        $this->em()->persist(new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'work', null, 'work', new \DateTimeImmutable(self::NOON)));
        $card->column = $this->column($card->project, 'in-progress');
        $this->em()->flush();
        self::assertCount(1, $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertCount(1, $this->liveRequests($card), 'a managed pass would cancel the request of a rule that left its slot');
        self::assertSame(['work-requested', 'unmanaged'], $this->keys($card));
    }

    public function test_a_claimed_request_with_no_worker_run_drops_the_waiting_marker(): void
    {
        $card = $this->boundCard();
        $this->hold($card);
        $this->persistRequest($card, 'work', WorkRequestState::Open);
        $this->evaluate($card);
        self::assertSame(['work-requested', 'unmanaged'], $this->keys($card));

        foreach ($this->liveRequests($card) as $request) {
            $request->state = WorkRequestState::Claimed;
        }
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['unmanaged'], $this->keys($card));
    }

    public function test_a_second_open_request_behind_a_claimed_one_keeps_the_waiting_marker(): void
    {
        $card = $this->boundCard();
        $this->hold($card);
        $this->persistRequest($card, 'work', WorkRequestState::Claimed);
        $this->persistRequest($card, 'fix', WorkRequestState::Open);

        $this->evaluate($card);

        self::assertSame(['work-requested', 'unmanaged'], $this->keys($card));
    }

    public function test_a_board_with_the_automation_off_still_gets_its_markers(): void
    {
        $card = $this->boundCard();
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand($card->project, false));

        $this->evaluate($card);

        self::assertSame([], $this->keys($card));
        self::assertSame([], $this->liveRequests($card));
        self::assertNotNull($card->markers, 'the pass stored an empty set');
    }

    public function test_the_pass_sends_one_markers_only_update_and_nothing_while_the_set_stays_the_same(): void
    {
        $card = $this->boundCard();
        $this->listen();

        $this->evaluate($card);
        $first = array_values(array_filter($this->changes, static fn (CardChanged $change): bool => $change->markersOnly));
        self::assertCount(1, $first);
        self::assertSame(CardChanged::UPDATED, $first[0]->change);
        self::assertTrue($first[0]->markersOnly);

        $this->changes = [];
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame([], array_filter($this->changes, static fn (CardChanged $change): bool => $change->markersOnly));
    }

    public function test_a_card_in_a_terminal_column_stores_an_empty_set(): void
    {
        $card = $this->boundCard();
        $this->evaluate($card);
        self::assertSame(['work-requested'], $this->keys($card));

        $card->column = $this->column($card->project, 'done');
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame([], $card->markers);
        self::assertNull($card->markersDueAt);
    }

    private function boundCard(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('marker-flow');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime']],
            'slots' => [['key' => 'one', 'label' => 'one'], ['key' => 'two', 'label' => 'two']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [['id' => 'work', 'slot' => 'one', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'work']]]],
            'markers' => [
                ['key' => 'work-requested', 'kind' => 'working', 'label' => 'marker.work_requested', 'when' => ['card.run.work_open' => []]],
                ['key' => 'unmanaged', 'kind' => 'chip', 'label' => 'marker.unmanaged', 'when' => ['card.held' => []]],
            ],
        ]));
        $this->em()->persist(new WorkflowSlotLink($project, 'one', $this->column($project, 'next')->id));
        $this->em()->persist(new WorkflowSlotLink($project, 'two', $this->column($project, 'in-progress')->id));
        $this->em()->flush();

        return $this->card($project, 'next');
    }

    private function evaluate(Card $card, string $at = self::NOON): void
    {
        $this->service(Engine::class)->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    private function persistRequest(Card $card, string $kind, WorkRequestState $state): void
    {
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, $kind, null, 'work', new \DateTimeImmutable(self::NOON));
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();
    }

    private function hold(Card $card): void
    {
        $this->service(CardHolds::class)->hold($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);
    }

    /** @return list<string> */
    private function keys(Card $card): array
    {
        return array_column($card->markers ?? [], 'key');
    }

    /** @return list<WorkRequest> */
    private function liveRequests(Card $card): array
    {
        return $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
    }

    private function listen(): void
    {
        $this->service(EventDispatcherInterface::class)->addListener(CardChanged::class, function (CardChanged $change): void {
            $this->changes[] = $change;
        });
    }
}
