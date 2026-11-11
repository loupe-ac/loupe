<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\BoardEventType;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ReorderCardsCommand;
use App\Module\Board\Command\ReorderCardsHandler;
use App\Module\Board\Command\ReorderCardsView;
use App\Module\Board\Command\ReorderedCard;
use App\Module\Board\Command\ReorderPlacement;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Event\CardsChanged;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A block of cards lands in one locked write, against the real database. */
final class ReorderCardsHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private const string OLD = '2020-01-01 00:00:00';

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private CreateCardHandler $createCard;
    private ReorderCardsHandler $reorder;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $reorder = self::getContainer()->get(ReorderCardsHandler::class);
        self::assertInstanceOf(ReorderCardsHandler::class, $reorder);
        $this->reorder = $reorder;

        $owner = new User(fullName: 'Riley', email: 'board-reorder-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_block_goes_to_the_top_in_list_order(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D', 'E']);

        $view = $this->place('next', [$cards['D'], $cards['B']], ReorderPlacement::Top);

        self::assertSame(['D', 'B', 'A', 'C', 'E'], $this->titles('next'));
        self::assertSame([[4, 'D', 0], [2, 'B', 1]], $this->rows($view->cards));
        self::assertNull($view->above);
        self::assertSame([1, 'A', 2], $this->rows([$view->below])[0]);
    }

    public function test_a_block_goes_after_a_card_and_the_answer_names_both_neighbours(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D', 'E']);

        $view = $this->place('next', [$cards['E'], $cards['A']], ReorderPlacement::After, $cards['C']);

        self::assertSame(['B', 'C', 'E', 'A', 'D'], $this->titles('next'));
        self::assertSame([3, 'C', 1], $this->rows([$view->above])[0]);
        self::assertSame([[5, 'E', 2], [1, 'A', 3]], $this->rows($view->cards));
        self::assertSame([4, 'D', 4], $this->rows([$view->below])[0]);
    }

    public function test_a_block_goes_before_a_card(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        $this->place('next', [$cards['D']], ReorderPlacement::Before, $cards['B']);

        self::assertSame(['A', 'D', 'B', 'C'], $this->titles('next'));
    }

    public function test_a_block_goes_to_the_bottom(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        $view = $this->place('next', [$cards['B'], $cards['A']], ReorderPlacement::Bottom);

        self::assertSame(['C', 'D', 'B', 'A'], $this->titles('next'));
        self::assertNull($view->below);
        self::assertSame([4, 'D', 1], $this->rows([$view->above])[0]);
    }

    public function test_a_list_of_every_card_reorders_the_whole_column(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        $this->place('next', [$cards['C'], $cards['A'], $cards['D'], $cards['B']], ReorderPlacement::Top);

        self::assertSame(['C', 'A', 'D', 'B'], $this->titles('next'));
        self::assertSame([0, 1, 2, 3], $this->ranks('next'));
    }

    public function test_an_unmoved_block_keeps_its_ranks(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->place('next', [$cards['A'], $cards['B']], ReorderPlacement::Top);

        self::assertSame(['A', 'B', 'C'], $this->titles('next'));
        self::assertSame([0, 1, 2], $this->ranks('next'));
    }

    public function test_only_a_listed_card_whose_rank_changes_gets_a_new_updated_at(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D', 'E']);
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET updated_at = :old', ['old' => self::OLD]);

        $this->place('next', [$cards['D'], $cards['B']], ReorderPlacement::Top);

        $stamped = $this->em->getConnection()->fetchFirstColumn('SELECT title FROM board_cards WHERE updated_at > :old ORDER BY title', ['old' => self::OLD]);
        // A and C shifted down without being listed, and B is listed but keeps rank 1.
        self::assertSame(['D'], $stamped);
        self::assertSame(['D', 'B', 'A', 'C', 'E'], $this->titles('next'));
    }

    public function test_every_listed_card_that_moves_is_stamped_and_no_other_card_is(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D', 'E']);
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET updated_at = :old', ['old' => self::OLD]);

        $this->place('next', [$cards['E'], $cards['D']], ReorderPlacement::Top);

        $stamped = $this->em->getConnection()->fetchFirstColumn('SELECT title FROM board_cards WHERE updated_at > :old ORDER BY title', ['old' => self::OLD]);
        self::assertSame(['D', 'E'], $stamped);
    }

    public function test_the_other_columns_keep_their_ranks(): void
    {
        $this->fill('backlog', ['X', 'Y']);
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->place('next', [$cards['C']], ReorderPlacement::Top);

        self::assertSame(['X', 'Y'], $this->titles('backlog'));
    }

    public function test_a_card_listed_twice_is_refused_and_named(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);
        $before = $this->snapshot();

        $errors = $this->refused('next', [$cards['B'], $cards['B']], ReorderPlacement::Top);

        self::assertSame(['cards.repeated' => ReorderCardsHandler::CARD_REPEATED], $errors->errors);
        self::assertSame(['%cards%' => '#'.$cards['B']->number], $errors->parameters['cards.repeated']);
        self::assertSame($before, $this->snapshot());
    }

    public function test_an_empty_list_is_refused(): void
    {
        $this->fill('next', ['A']);
        $before = $this->snapshot();

        $errors = $this->refused('next', [], ReorderPlacement::Top);

        self::assertSame(['cards' => ReorderCardsHandler::LIST_EMPTY], $errors->errors);
        self::assertSame($before, $this->snapshot());
    }

    public function test_a_card_of_another_column_is_refused_and_named(): void
    {
        $cards = $this->fill('next', ['D', 'E']);
        $stranger = $this->fill('backlog', ['F'])['F'];
        $before = $this->snapshot();

        $errors = $this->refused('next', [$cards['D'], $stranger], ReorderPlacement::Top);

        self::assertSame(['cards.elsewhere' => ReorderCardsHandler::CARD_ELSEWHERE], $errors->errors);
        self::assertSame(['%cards%' => '#'.$stranger->number, '%column%' => 'next'], $errors->parameters['cards.elsewhere']);
        self::assertSame($before, $this->snapshot());
    }

    public function test_a_neighbour_that_is_in_the_list_is_refused(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);
        $before = $this->snapshot();

        $errors = $this->refused('next', [$cards['A'], $cards['B']], ReorderPlacement::Before, $cards['A']);

        self::assertSame(['beforeCard' => ReorderCardsHandler::NEIGHBOUR_LISTED], $errors->errors);
        self::assertSame($before, $this->snapshot());
    }

    public function test_a_neighbour_of_another_column_is_refused(): void
    {
        $cards = $this->fill('next', ['A', 'B']);
        $stranger = $this->fill('backlog', ['F'])['F'];
        $before = $this->snapshot();

        $errors = $this->refused('next', [$cards['A']], ReorderPlacement::After, $stranger);

        self::assertSame(['afterCard' => 'board.card.error.neighbour_elsewhere'], $errors->errors);
        self::assertSame(['%card%' => '#'.$stranger->number, '%where%' => 'the backlog column', '%column%' => 'next'], $errors->parameters['afterCard']);
        self::assertSame($before, $this->snapshot());
    }

    public function test_every_card_at_fault_is_named_in_one_error(): void
    {
        $cards = $this->fill('next', ['A', 'B']);
        $stranger = $this->fill('backlog', ['F'])['F'];

        $errors = $this->refused('next', [$cards['A'], $cards['A'], $stranger], ReorderPlacement::Before, $cards['A']);

        self::assertEqualsCanonicalizing(['cards.repeated', 'cards.elsewhere', 'beforeCard'], array_keys($errors->errors));
    }

    public function test_a_terminal_column_is_refused(): void
    {
        $done = $this->fill('done', ['A', 'B']);
        $before = $this->snapshot();

        $errors = $this->refused('done', [$done['B']], ReorderPlacement::Top);

        self::assertSame(['column' => 'board.card.error.place_terminal'], $errors->errors);
        self::assertSame(['%column%' => 'done'], $errors->parameters['column']);
        self::assertSame($before, $this->snapshot());
    }

    public function test_a_block_sends_no_move_and_no_outbox_row_and_one_record(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);
        $moved = DispatchedEvents::of(self::getContainer(), CardMoved::class);
        $this->audit->forget();
        $rowsBefore = $this->outboxRows(BoardEventType::CARD_MOVED);

        $this->place('next', [$cards['C'], $cards['B']], ReorderPlacement::Top);

        self::assertSame([], $moved->events());
        self::assertSame($rowsBefore, $this->outboxRows(BoardEventType::CARD_MOVED));
        self::assertSame(['board.cards_reordered'], $this->audit->operations());
        $record = $this->audit->record('board.cards_reordered');
        self::assertSame(2, $record->context['cardCount']);
        self::assertSame('next', $record->context['columnSlug']);
        self::assertSame('top', $record->context['placement']);
    }

    public function test_a_refusal_records_nothing_and_announces_nothing(): void
    {
        $cards = $this->fill('next', ['A']);
        $changed = DispatchedEvents::of(self::getContainer(), CardsChanged::class);
        $this->audit->forget();

        $this->refused('next', [$cards['A'], $cards['A']], ReorderPlacement::Top);

        self::assertSame([], $changed->events());
        self::assertSame([], $this->audit->operations());
    }

    public function test_the_listed_cards_are_announced_once_each_in_list_order(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);
        $single = DispatchedEvents::of(self::getContainer(), CardChanged::class);
        $batch = DispatchedEvents::of(self::getContainer(), CardsChanged::class);
        $base = $this->em->getConnection()->getTransactionNestingLevel();

        $this->place('next', [$cards['C'], $cards['A']], ReorderPlacement::Top);

        self::assertSame([$cards['C']->id?->toRfc4122(), $cards['A']->id?->toRfc4122()], array_map(static fn (CardChanged $event): string => $event->cardId->toRfc4122(), $single->events()));
        self::assertCount(1, $batch->events());
        self::assertSame([$cards['C']->id?->toRfc4122(), $cards['A']->id?->toRfc4122()], array_map(static fn ($id): string => $id->toRfc4122(), $batch->events()[0]->cardIds));
        self::assertSame([$base, $base], $single->transactionDepths());
    }

    public function test_a_backlog_block_redraws_each_epic_once(): void
    {
        $epicOne = $this->epic('Epic one');
        $epicTwo = $this->epic('Epic two');
        $children = [
            $this->child('A1', $epicOne),
            $this->child('A2', $epicOne),
            $this->child('B1', $epicTwo),
            $this->child('A3', $epicOne),
        ];
        $single = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        $this->place('backlog', [$children[3], $children[2], $children[1]], ReorderPlacement::Top);

        $announced = array_map(static fn (CardChanged $event): string => $event->cardId->toRfc4122(), $single->events());
        foreach ([$epicOne, $epicTwo] as $epic) {
            self::assertSame(1, \count(array_keys($announced, $epic->id?->toRfc4122(), true)), 'one update for '.$epic->title);
        }
        self::assertSame(['A3', 'B1', 'A2', 'Epic one', 'Epic two', 'A1'], $this->titles('backlog'));
        self::assertCount(3 + 2, $announced);
    }

    public function test_an_open_column_other_than_backlog_redraws_no_epic(): void
    {
        $epic = $this->epic('Epic');
        $first = $this->child('First', $epic, 'next');
        $second = $this->child('Second', $epic, 'next');
        $single = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        $this->place('next', [$second, $first], ReorderPlacement::Top);

        $announced = array_map(static fn (CardChanged $event): string => $event->cardId->toRfc4122(), $single->events());
        self::assertNotContains($epic->id?->toRfc4122(), $announced);
        self::assertCount(2, $announced);
    }

    public function test_a_full_reorder_of_a_large_backlog_lands_in_one_pass(): void
    {
        $count = 600;
        $backlog = $this->fill('backlog', array_map(static fn (int $i): string => 'C'.$i, range(1, $count)));
        $order = array_reverse(array_values($backlog));

        $view = $this->place('backlog', $order, ReorderPlacement::Top);

        self::assertSame($count, \count($view->cards));
        self::assertSame(range(0, $count - 1), $this->ranks('backlog'));
        self::assertSame('C'.$count, $this->titles('backlog')[0]);
    }

    /**
     * @param list<string> $titles
     *
     * @return array<string, Card> the cards by title
     */
    private function fill(string $slug, array $titles): array
    {
        $cards = [];
        foreach ($titles as $title) {
            $cards[$title] = $this->card($title, $slug);
        }

        return $cards;
    }

    private function card(string $title, string $slug, string $type = 'feature', ?Card $parent = null): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: '',
            type: $type,
            column: $this->column($this->project, $slug),
            parentCardId: $parent?->id?->toRfc4122(),
        ));
    }

    private function epic(string $title): Card
    {
        return $this->card($title, 'backlog', 'epic');
    }

    private function child(string $title, Card $epic, string $slug = 'backlog'): Card
    {
        return $this->card($title, $slug, 'feature', $epic);
    }

    /** @param list<Card> $block */
    private function place(string $slug, array $block, ReorderPlacement $placement, ?Card $neighbour = null): ReorderCardsView
    {
        return ($this->reorder)($this->command($slug, $block, $placement, $neighbour));
    }

    /** @param list<Card> $block */
    private function refused(string $slug, array $block, ReorderPlacement $placement, ?Card $neighbour = null): DomainErrors
    {
        try {
            ($this->reorder)($this->command($slug, $block, $placement, $neighbour));
        } catch (DomainErrors $e) {
            return $e;
        }

        self::fail('The call must be refused.');
    }

    /** @param list<Card> $block */
    private function command(string $slug, array $block, ReorderPlacement $placement, ?Card $neighbour): ReorderCardsCommand
    {
        return new ReorderCardsCommand(
            project: $this->project,
            column: $this->column($this->project, $slug),
            cardIds: array_map(static fn (Card $card): string => $card->id?->toRfc4122() ?? '', $block),
            placement: $placement,
            neighbourId: $neighbour?->id?->toRfc4122(),
            actor: Actor::Agent,
        );
    }

    /**
     * Read back over SQL, so the order is what was written rather than what is in memory.
     *
     * @return list<string>
     */
    private function titles(string $slug): array
    {
        /* @var list<string> */
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT title FROM board_cards WHERE column_id = :column ORDER BY position, created_at, id',
            ['column' => (string) $this->column($this->project, $slug)->id],
        );
    }

    /** @return list<int> */
    private function ranks(string $slug): array
    {
        /* @var list<int> */
        return array_map(intval(...), $this->em->getConnection()->fetchFirstColumn(
            'SELECT position FROM board_cards WHERE column_id = :column ORDER BY position, created_at, id',
            ['column' => (string) $this->column($this->project, $slug)->id],
        ));
    }

    /** @return array<string, array{int, string}> the rank and the stamp of every card */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id, position, updated_at FROM board_cards ORDER BY id') as $row) {
            $snapshot[(string) $row['id']] = [(int) $row['position'], (string) $row['updated_at']];
        }

        return $snapshot;
    }

    /**
     * @param list<ReorderedCard|null> $cards
     *
     * @return list<array{int, string, int}>
     */
    private function rows(array $cards): array
    {
        return array_map(static fn ($card): array => [$card->number, $card->title, $card->rank], array_values(array_filter($cards)));
    }

    private function outboxRows(string $type): int
    {
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);

        return \count($outbox->findBy(['project' => $this->project->id, 'type' => $type]));
    }
}
