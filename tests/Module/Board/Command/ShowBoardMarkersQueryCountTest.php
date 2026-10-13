<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The markers are stored on the card row, so the board page reads no state and costs no query per card. */
final class ShowBoardMarkersQueryCountTest extends KernelTestCase
{
    use CardStateFixtures;

    private const array STATE_TABLES = ['card_pauses', 'work_requests', 'inbox_item_cards', 'workflow_rule_states', 'bridge_card_holds', 'board_card_events', 'forge_pull_requests'];

    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;
    }

    public function test_fifty_cards_cost_the_board_no_more_queries_than_five(): void
    {
        $small = $this->board('markers-q-small', 5);
        $big = $this->board('markers-q-big', 50);
        $this->em()->clear();

        [$forSmall, $smallMarked] = $this->showing($small);
        [$forBig, $bigMarked] = $this->showing($big);

        // Guards: half of the cards hold markers, and the page read cards. Without them an empty board hides a query per card.
        self::assertSame(3, $smallMarked);
        self::assertSame(25, $bigMarked);
        self::assertNotEmpty(array_filter($forBig, static fn (string $sql): bool => str_contains($sql, 'board_cards')));
        self::assertSame(\count($forSmall), \count($forBig), "The board query count grew with the cards:\n".implode("\n", $forBig));
    }

    public function test_the_board_reads_none_of_the_tables_that_once_gave_a_card_its_state(): void
    {
        $board = $this->board('markers-q-tables', 10);
        $this->em()->clear();

        [$statements] = $this->showing($board);

        self::assertNotEmpty($statements);
        foreach (self::STATE_TABLES as $table) {
            self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, $table))), $table.' was read.');
        }
    }

    /** Every other card holds a stuck marker, and the rest hold none. */
    private function board(string $name, int $cards): Project
    {
        $project = $this->stateProject($name);
        for ($i = 0; $i < $cards; ++$i) {
            $card = $this->stateCard($project, 'tech-design');
            if (0 === $i % 2) {
                $card->markers = [['key' => 'checks_failed', 'kind' => 'stuck', 'label' => 'marker.checks_failed', 'remedy' => 'marker.checks_failed.remedy', 'params' => ['%number%' => $i], 'since' => '2026-10-02T09:00:00+00:00']];
            }
        }
        $this->em()->flush();

        return $project;
    }

    /** @return array{list<string>, int} the statements, and the number of cards that hold markers */
    private function showing(Project $board): array
    {
        $project = $this->em()->find(Project::class, $board->id) ?? throw new \LogicException('The project is stored.');
        $handler = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $handler);

        $this->queries->reset();
        $view = $handler(new ShowBoardCommand($project));

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return [$statements, \count($view->markers)];
    }
}
