<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261125024053;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261125024053.php';

final class DiscoveryRunsLeaveCardsMigrationTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-leave-cards');
    }

    /** @return iterable<string, array{DiscoveryRunState, string, WorkRequestState}> */
    public static function liveRuns(): iterable
    {
        yield 'requested, open' => [DiscoveryRunState::Requested, 'discovery', WorkRequestState::Open];
        yield 'requested, claimed' => [DiscoveryRunState::Requested, 'discovery', WorkRequestState::Claimed];
        yield 'revising, open' => [DiscoveryRunState::Revising, 'discovery-revise', WorkRequestState::Open];
    }

    #[DataProvider('liveRuns')]
    public function test_the_live_request_of_a_live_run_and_its_workers_move_to_the_run(DiscoveryRunState $state, string $kind, WorkRequestState $requestState): void
    {
        $card = $this->card($this->project);
        $run = $this->discoveryRun($this->project, $state, card: $card);
        $request = $this->legacyCardRequest($card, $kind, $requestState);
        $worker = $this->legacyWorkerRun($card, $request);

        $this->migrate();

        $this->assertMovedToRun($run, $request, $worker);
        self::assertSame($requestState->value, $this->requestRow($request)['state']);
        self::assertTrue($this->storedRun($run)->workRequestId?->equals($request->id));
        self::assertSame('backlog', $this->columnOf($card)['slug']);
    }

    /** The worker that reported can still be at work, and the run waits for a revision that no request asks for yet. */
    public function test_a_revising_run_with_a_live_discovery_request_alone_moves_it_and_stores_no_id(): void
    {
        $card = $this->card($this->project);
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Revising, card: $card);
        $request = $this->legacyCardRequest($card, 'discovery', WorkRequestState::Claimed);

        $this->migrate();

        $this->assertMovedToRun($run, $request);
        self::assertNull($this->storedRun($run)->workRequestId);
    }

    public function test_requests_that_belong_to_no_live_run_stay_on_their_cards(): void
    {
        $live = $this->card($this->project);
        $this->discoveryRun($this->project, card: $live);
        $otherRule = $this->legacyCardRequest($live, 'implement');
        $settled = $this->legacyCardRequest($live, 'discovery', WorkRequestState::Done);
        $ended = $this->card($this->project);
        $this->discoveryRun($this->project, DiscoveryRunState::Reported, card: $ended);
        $ofEndedRun = $this->legacyCardRequest($ended);
        $plain = $this->legacyCardRequest($this->card($this->project));

        $this->migrate();

        foreach ([$otherRule, $settled, $ofEndedRun, $plain] as $request) {
            self::assertSame('card', $this->requestRow($request)['subject_type']);
        }
    }

    /** @return iterable<string, array{DiscoveryRunState}> */
    public static function endedStates(): iterable
    {
        yield 'reported' => [DiscoveryRunState::Reported];
        yield 'failed' => [DiscoveryRunState::Failed];
        yield 'done' => [DiscoveryRunState::Done];
    }

    #[DataProvider('endedStates')]
    public function test_the_card_of_an_ended_run_moves_to_the_first_terminal_column(DiscoveryRunState $state): void
    {
        $first = $this->card($this->project);
        $moving = $this->card($this->project);
        $last = $this->card($this->project);
        foreach ([$first, $moving, $last] as $position => $card) {
            $card->position = $position;
        }
        $this->discoveryRun($this->project, $state, card: $moving);

        $this->migrate();

        $terminal = $this->em()->getConnection()->fetchOne('SELECT slug FROM board_columns WHERE project_id = ? AND terminal ORDER BY position, id LIMIT 1', [(string) $this->project->id]);
        $row = $this->columnOf($moving);
        self::assertSame([$terminal, 0, 1, true], [$row['slug'], $row['position'], $row['completions'], $row['left_backlog']]);
        self::assertNotNull($row['completed_at']);
        // The column it left keeps ranks with no gaps.
        self::assertSame([0, 1], [$this->columnOf($first)['position'], $this->columnOf($last)['position']]);
        self::assertSame(['backlog', null], [$this->columnOf($first)['slug'], $this->columnOf($first)['completed_at']]);
    }

    public function test_a_card_stays_while_one_of_its_runs_is_live(): void
    {
        $card = $this->card($this->project);
        $this->discoveryRun($this->project, DiscoveryRunState::Failed, '2026-10-02 10:00:00', $card);
        $this->discoveryRun($this->project, DiscoveryRunState::Revising, '2026-10-02 11:00:00', $card);

        $this->migrate();

        self::assertSame('backlog', $this->columnOf($card)['slug']);
    }

    public function test_a_card_in_a_terminal_column_keeps_its_completion(): void
    {
        $card = $this->card($this->project, 'done');
        $this->discoveryRun($this->project, DiscoveryRunState::Done, card: $card);
        $this->em()->getConnection()->executeStatement("UPDATE board_cards SET completed_at = '2026-10-01 08:00:00', completions = 1 WHERE id = ?", [(string) $card->id]);
        $before = $this->columnOf($card);

        $this->migrate();

        self::assertSame($before, $this->columnOf($card));
    }

    /** @return array{slug: string, position: int, completions: int, left_backlog: bool, completed_at: ?string} */
    private function columnOf(Card $card): array
    {
        /** @var array{slug: string, position: int, completions: int, left_backlog: bool, completed_at: ?string} $row */
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT col.slug, c.position, c.completions, c.left_backlog, c.completed_at FROM board_cards c JOIN board_columns col ON col.id = c.column_id WHERE c.id = ?',
            [(string) $card->id],
        );

        return $row;
    }

    private function migrate(): void
    {
        $this->em()->flush();
        $connection = $this->em()->getConnection();
        $migration = new Version20261125024053($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
