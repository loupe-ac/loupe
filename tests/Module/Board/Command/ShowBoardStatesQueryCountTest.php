<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardStateCode;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The state of a card is read in batches, so a board with ten times the cards costs the page no more queries. */
final class ShowBoardStatesQueryCountTest extends KernelTestCase
{
    use CardStateFixtures;

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
        $small = $this->board('states-q-small', 5);
        $big = $this->board('states-q-big', 50);
        $this->em()->clear();

        [$forSmall, $smallStates] = $this->showing($small);
        [$forBig, $bigStates] = $this->showing($big);

        // Guards: half of the cards hold a state, and each batch ran. Without them an empty batch hides a query per card.
        self::assertSame(3, $smallStates);
        self::assertSame(25, $bigStates);
        foreach (['card_pauses', 'work_requests', 'bridge_worker_runs', 'inbox_item_cards', 'workflow_rule_states', 'board_card_documents'] as $table) {
            self::assertNotEmpty(array_filter($forBig, static fn (string $sql): bool => str_contains($sql, $table)), $table.' was never read.');
        }
        self::assertSame(\count($forSmall), \count($forBig), "The board query count grew with the cards:\n".implode("\n", $forBig));
    }

    public function test_fifty_quiet_epic_children_cost_the_board_no_more_queries_than_five(): void
    {
        $small = $this->quietChildren('states-q-quiet-small', 5);
        $big = $this->quietChildren('states-q-quiet-big', 50);
        $this->em()->clear();

        [$forSmall, $smallStates] = $this->showing($small, CardStateCode::PullRequestIdle);
        [$forBig, $bigStates] = $this->showing($big, CardStateCode::PullRequestIdle);

        self::assertSame(5, $smallStates);
        self::assertSame(50, $bigStates);
        self::assertSame(\count($forSmall), \count($forBig), "The board query count grew with the quiet children:\n".implode("\n", $forBig));
    }

    /** Each epic waits in Next, with one child whose pull request on the epic branch nothing moves. */
    private function quietChildren(string $name, int $epics): Project
    {
        $project = $this->stateProject($name);
        for ($i = 0; $i < $epics; ++$i) {
            $epic = $this->stateCard($project, 'next');
            $child = $this->stateCard($project);
            $child->parent = $epic;
            $this->linkPullRequest($child, ['draft' => true, 'checks' => PullRequestChecks::Passed, 'baseBranch' => 'epic/'.$epic->number]);
            $this->recordHistory($child, '-1 hour');
        }

        return $project;
    }

    /** Every other card holds one of seven facts, in turn. The rest hold none. */
    private function board(string $name, int $cards): Project
    {
        $project = $this->stateProject($name);
        $facts = [
            fn (Card $card) => $this->reviewDocument($card),
            fn (Card $card) => $this->linkPullRequest($card, ['checks' => PullRequestChecks::Failed]),
            fn (Card $card) => $this->pauseCard($card),
            fn (Card $card) => $this->requestWork($card),
            fn (Card $card) => $this->askOwner($card),
            fn (Card $card) => $this->holdByBlocker($card),
            fn (Card $card) => $this->openRun($card),
        ];
        for ($i = 0; $i < $cards; ++$i) {
            $card = $this->stateCard($project, 'tech-design');
            if (0 === $i % 2) {
                $facts[intdiv($i, 2) % \count($facts)]($card);
            }
        }

        return $project;
    }

    /** @return array{list<string>, int} the statements, and the number of cards that hold a state */
    private function showing(Project $board, CardStateCode $expected = CardStateCode::DocumentInReview): array
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
        $codes = array_map(static fn ($state): string => $state->reason->code->value, $view->states);
        self::assertContains($expected->value, $codes);

        return [$statements, \count($view->states)];
    }
}
