<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardReorderTool;
use App\Module\Project\Entity\Project;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardReorderToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardReorderTool $tool;
    private CardCreateTool $createTool;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardReorderTool::class);
        self::assertInstanceOf(CardReorderTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;

        $this->project = $this->makeProject('card-reorder');
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_block_of_numbers_and_ids_lands_at_the_top_and_the_answer_names_its_neighbour(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D', 'E']);

        $result = ($this->tool)('next', [$cards['D']['number'], $cards['B']['cardId']], at: 'top');

        self::assertSame(['D', 'B', 'A', 'C', 'E'], $this->titles('next'));
        self::assertNull($result['above']);
        self::assertSame([['number' => $cards['D']['number'], 'title' => 'D', 'rank' => 0], ['number' => $cards['B']['number'], 'title' => 'B', 'rank' => 1]], $result['cards']);
        self::assertSame(['number' => $cards['A']['number'], 'title' => 'A', 'rank' => 2], $result['below']);
    }

    public function test_a_block_lands_after_a_card_named_by_a_string_of_digits(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D', 'E']);

        $result = ($this->tool)('next', [(string) $cards['E']['number'], $cards['A']['number']], afterCard: (string) $cards['C']['number']);

        self::assertSame(['B', 'C', 'E', 'A', 'D'], $this->titles('next'));
        self::assertSame('C', $result['above']['title'] ?? null);
        self::assertSame('D', $result['below']['title'] ?? null);
    }

    public function test_a_block_lands_before_a_card_and_at_the_bottom(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C', 'D']);

        ($this->tool)('next', [$cards['D']['number']], beforeCard: $cards['B']['cardId']);
        self::assertSame(['A', 'D', 'B', 'C'], $this->titles('next'));

        ($this->tool)('next', [$cards['A']['number']], at: 'bottom');
        self::assertSame(['D', 'B', 'C', 'A'], $this->titles('next'));
    }

    public function test_the_backlog_is_ranked_like_any_open_column(): void
    {
        $cards = $this->fill('backlog', ['A', 'B', 'C']);

        ($this->tool)('backlog', [$cards['C']['number']], at: 'top');

        self::assertSame(['C', 'A', 'B'], $this->titles('backlog'));
    }

    public function test_a_call_with_no_placement_is_refused_and_nothing_moves(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass one of at, beforeCard and afterCard to say where the block goes.');
        try {
            ($this->tool)('next', [$cards['C']['number']]);
        } finally {
            self::assertSame(['A', 'B', 'C'], $this->titles('next'));
        }
    }

    public function test_two_placements_are_refused(): void
    {
        $cards = $this->fill('next', ['A', 'B', 'C']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass only one of at, beforeCard and afterCard.');
        ($this->tool)('next', [$cards['C']['number']], at: 'top', beforeCard: $cards['A']['number']);
    }

    public function test_an_unknown_at_value_is_refused(): void
    {
        $cards = $this->fill('next', ['A']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown at "middle". Use top or bottom.');
        ($this->tool)('next', [$cards['A']['number']], at: 'middle');
    }

    public function test_an_unknown_column_is_refused_with_the_slugs_the_board_has(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown column "review". Use one of: ');
        ($this->tool)('review', [1], at: 'top');
    }

    public function test_every_unknown_reference_is_named_in_one_error(): void
    {
        $cards = $this->fill('next', ['A', 'B']);

        try {
            ($this->tool)('next', [$cards['A']['number'], 999, '0199a0a0-0000-7000-8000-000000000000', 'nonsense'], beforeCard: 888);
            self::fail('The call must be refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('This project has no card 999, 0199a0a0-0000-7000-8000-000000000000, nonsense.', $e->getMessage());
            self::assertStringContainsString('This project has no card 888.', $e->getMessage());
        }
        self::assertSame(['A', 'B'], $this->titles('next'));
    }

    public function test_a_card_of_another_project_reads_as_unknown(): void
    {
        $this->fill('next', ['A']);
        $other = $this->makeProject('card-reorder-other');
        $this->actAsMcpTokenBoundTo($other);
        $stranger = ($this->createTool)('Stranger', '', 'feature', status: 'next');
        $this->actAsMcpTokenBoundTo($this->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This project has no card '.$stranger['cardId'].'.');
        ($this->tool)('next', [$stranger['cardId']], at: 'top');
    }

    public function test_a_list_that_repeats_a_card_is_refused_and_names_it(): void
    {
        $cards = $this->fill('next', ['A', 'B']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('These cards appear more than once: #'.$cards['B']['number'].'. Name each card once.');
        try {
            ($this->tool)('next', [$cards['B']['number'], $cards['B']['cardId']], at: 'top');
        } finally {
            self::assertSame(['A', 'B'], $this->titles('next'));
        }
    }

    public function test_a_card_of_another_column_is_refused_and_names_it(): void
    {
        $cards = $this->fill('next', ['D']);
        $stranger = $this->fill('backlog', ['F'])['F'];

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('These cards are not in the next column: #'.$stranger['number'].'. To move a card into a column, use card_update.');
        ($this->tool)('next', [$cards['D']['number'], $stranger['number']], at: 'top');
    }

    public function test_a_neighbour_in_the_list_is_refused(): void
    {
        $cards = $this->fill('next', ['A', 'B']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Card #'.$cards['A']['number'].' is in the list. Name a neighbour that is not in the list.');
        ($this->tool)('next', [$cards['A']['number']], beforeCard: $cards['A']['number']);
    }

    public function test_an_empty_list_is_refused(): void
    {
        $this->fill('next', ['A']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Name at least one card to place.');
        ($this->tool)('next', [], at: 'top');
    }

    public function test_a_terminal_column_is_refused(): void
    {
        $done = $this->fill('done', ['A']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The done column sorts by completion time');
        ($this->tool)('done', [$done['A']['number']], at: 'top');
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $this->actAsUnboundMcpToken($this->project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)('next', [1], at: 'top');
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
}
