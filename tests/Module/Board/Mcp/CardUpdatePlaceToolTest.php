<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Project\Entity\Project;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** card_update with a rank or a neighbour places the card the way a drag does. */
final class CardUpdatePlaceToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardUpdateTool $tool;
    private CardCreateTool $createTool;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;

        $this->project = $this->makeProject('card-place');
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_rank_puts_the_card_there_and_the_others_shift_down(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        $result = ($this->tool)($cards['D']['cardId'], position: 0);

        self::assertSame(0, $result['position']);
        self::assertSame(['D', 'A', 'B', 'C'], $this->titles('next'));
    }

    public function test_a_rank_writes_no_history_row(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);
        $before = $this->eventCount($cards['C']['cardId']);

        ($this->tool)($cards['C']['cardId'], position: 0);

        self::assertSame(['C', 'A', 'B'], $this->titles('next'));
        self::assertSame($before, $this->eventCount($cards['C']['cardId']));
    }

    public function test_a_neighbour_named_by_number_places_the_card_just_above_it(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        ($this->tool)(number: $cards['D']['number'], beforeCard: $cards['B']['number']);

        self::assertSame(['A', 'D', 'B', 'C'], $this->titles('next'));
    }

    public function test_a_neighbour_named_by_id_places_the_card_just_below_it(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        ($this->tool)($cards['A']['cardId'], afterCard: $cards['C']['cardId']);

        self::assertSame(['B', 'C', 'A', 'D'], $this->titles('next'));
    }

    public function test_a_neighbour_named_by_a_string_of_digits_is_a_number(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        ($this->tool)($cards['C']['cardId'], beforeCard: (string) $cards['A']['number']);

        self::assertSame(['C', 'A', 'B'], $this->titles('next'));
    }

    public function test_a_rank_past_the_end_puts_the_card_last(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        ($this->tool)($cards['A']['cardId'], position: 99);

        self::assertSame(['B', 'C', 'A'], $this->titles('next'));
    }

    public function test_a_status_and_a_rank_move_and_place_the_card_in_one_call(): void
    {
        $this->fill('next', ['A', 'B', 'C']);
        $mover = ($this->createTool)('Mover', '', 'feature', status: 'backlog');

        $result = ($this->tool)($mover['cardId'], status: 'next', position: 0);

        self::assertSame('next', $result['status']);
        self::assertSame(['Mover', 'A', 'B', 'C'], $this->titles('next'));
    }

    public function test_a_status_and_a_neighbour_place_the_card_in_the_new_column(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);
        $mover = ($this->createTool)('Mover', '', 'feature', status: 'backlog');

        ($this->tool)($mover['cardId'], status: 'next', afterCard: $cards['B']['number']);

        self::assertSame(['A', 'B', 'Mover', 'C'], $this->titles('next'));
    }

    public function test_a_rank_and_a_neighbour_together_change_nothing(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass at most one of position, beforeCard and afterCard.');
        try {
            ($this->tool)($cards['C']['cardId'], position: 0, beforeCard: $cards['A']['number']);
        } finally {
            self::assertSame(['A', 'B', 'C'], $this->titles('next'));
        }
    }

    public function test_two_neighbours_together_change_nothing(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass at most one of position, beforeCard and afterCard.');
        ($this->tool)($cards['C']['cardId'], beforeCard: $cards['A']['number'], afterCard: $cards['B']['number']);
    }

    public function test_a_neighbour_in_another_column_is_refused_and_named_with_the_column(): void
    {
        $cards = $this->fill('next', ['A', 'B']);
        $stranger = ($this->createTool)('Stranger', '', 'feature', status: 'in-progress');

        try {
            ($this->tool)($cards['A']['cardId'], afterCard: $stranger['number']);
            self::fail('The call must be refused.');
        } catch (ToolCallException $e) {
            self::assertSame(\sprintf('afterCard: Card #%d sits in the in-progress column, not in the next column where this card ends. Name a card of the next column.', $stranger['number']), $e->getMessage());
        }
        self::assertSame(['A', 'B'], $this->titles('next'));
        self::assertSame(['Stranger'], $this->titles('in-progress'));
    }

    public function test_a_neighbour_of_the_target_column_is_checked_when_the_status_changes(): void
    {
        $this->fill('next', ['A', 'B']);
        $mover = ($this->createTool)('Mover', '', 'feature', status: 'backlog');
        $sibling = ($this->createTool)('Sibling', '', 'feature', status: 'backlog');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not in the next column where this card ends');
        try {
            ($this->tool)($mover['cardId'], status: 'next', beforeCard: $sibling['number']);
        } finally {
            self::assertSame(['A', 'B'], $this->titles('next'));
            self::assertSame('backlog', ($this->tool)($mover['cardId'])['status']);
        }
    }

    public function test_the_card_itself_is_not_a_neighbour(): void
    {
        $cards = $this->fill('next', ['A', 'B']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('beforeCard: A card cannot be placed next to itself.');
        try {
            ($this->tool)($cards['A']['cardId'], beforeCard: $cards['A']['number']);
        } finally {
            self::assertSame(['A', 'B'], $this->titles('next'));
        }
    }

    public function test_unknown_neighbours_are_refused_and_named(): void
    {
        $cards = $this->fill('next', ['A', 'B']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('beforeCard: This project has no card 999.');
        ($this->tool)($cards['A']['cardId'], beforeCard: 999);
    }

    public function test_a_card_id_of_no_card_is_named_too(): void
    {
        $cards = $this->fill('next', ['A', 'B']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('afterCard: This project has no card 0199a0a0-0000-7000-8000-000000000000.');
        ($this->tool)($cards['A']['cardId'], afterCard: '0199a0a0-0000-7000-8000-000000000000');
    }

    public function test_a_negative_rank_is_refused(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('position: A rank counts from 0. Pass 0 or more.');
        try {
            ($this->tool)($cards['C']['cardId'], position: -1);
        } finally {
            self::assertSame(['A', 'B', 'C'], $this->titles('next'));
        }
    }

    public function test_a_rank_aimed_at_a_terminal_column_is_refused_and_the_card_stays(): void
    {
        $cards = $this->fill('next', ['A']);

        try {
            ($this->tool)($cards['A']['cardId'], status: 'done', position: 0);
            self::fail('The call must be refused.');
        } catch (ToolCallException $e) {
            self::assertSame('position: The done column sorts by completion time, so it takes no rank or neighbour. Move the card without a place.', $e->getMessage());
        }
        self::assertSame(['A'], $this->titles('next'));
        self::assertSame([], $this->titles('done'));
    }

    public function test_a_neighbour_aimed_at_a_terminal_column_is_refused(): void
    {
        $cards = $this->fill('next', ['A']);
        $finished = ($this->createTool)('Finished', '', 'feature', status: 'done');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('beforeCard: The done column sorts by completion time');
        ($this->tool)($cards['A']['cardId'], status: 'done', beforeCard: $finished['number']);
    }

    public function test_a_rank_for_a_card_that_sits_in_a_terminal_column_is_refused(): void
    {
        $finished = ($this->createTool)('Finished', '', 'feature', status: 'done');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The done column sorts by completion time');
        ($this->tool)($finished['cardId'], position: 0);
    }

    public function test_a_move_to_a_terminal_column_with_no_place_works_as_before(): void
    {
        $cards = $this->fill('next', ['A']);

        $result = ($this->tool)($cards['A']['cardId'], status: 'done');

        self::assertSame('done', $result['status']);
        self::assertNotNull($result['completedAt']);
    }

    public function test_a_call_with_no_place_keeps_the_rank(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        ($this->tool)($cards['B']['cardId'], title: 'Renamed');

        self::assertSame(['A', 'Renamed', 'C'], $this->titles('next'));
    }

    /**
     * @param list<string> $titles
     *
     * @return array<string, array{cardId: string, number: int}> the cards by title
     */
    private function fill(string $status, array $titles): array
    {
        $cards = [];
        foreach ($titles as $title) {
            $cards[$title] = ($this->createTool)($title, '', 'feature', status: $status);
        }

        return $cards;
    }

    /**
     * Read over SQL, so the order is what was written rather than what is in memory.
     *
     * @return list<string>
     */
    private function titles(string $status): array
    {
        /* @var list<string> */
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT title FROM board_cards WHERE column_id = :column ORDER BY position, created_at, id',
            ['column' => (string) $this->column($this->project, $status)->id],
        );
    }

    private function eventCount(string $cardId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_card_events WHERE card_id = :id', ['id' => $cardId]);
    }
}
