<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261125194856;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261125194856.php';

final class DiscoveryRunsEndOnArchivedReportMigrationTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    private int $itemNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-archived-report-migration');
    }

    public function test_a_reported_run_with_an_archived_report_ends(): void
    {
        $run = $this->runWithReport(DiscoveryRunState::Reported, archived: true);

        $this->migrate();

        $stored = $this->storedRun($run);
        self::assertSame(DiscoveryRunState::Superseded, $stored->state);
        self::assertNotNull($stored->endedAt);
        self::assertEqualsWithDelta(time(), $stored->endedAt->getTimestamp(), 120);
    }

    public function test_the_open_review_of_an_ended_run_closes(): void
    {
        $item = $this->reviewItem($this->runWithReport(DiscoveryRunState::Reported, archived: true));

        $this->migrate();

        $stored = $this->storedItem($item);
        self::assertSame(
            [InboxItemState::Cancelled, InboxCloser::Loupe, InboxCloseCause::FactEnded, 'A newer discovery run replaced this report, or a person archived it.'],
            [$stored->state, $stored->closedBy, $stored->closeCause, $stored->note],
        );
        self::assertNotNull($stored->closedAt);
        self::assertEqualsWithDelta(time(), $stored->closedAt->getTimestamp(), 120);
        self::assertEquals($stored->closedAt, $stored->updatedAt);
    }

    public function test_the_review_of_a_run_that_stays_reported_stays_open(): void
    {
        $ended = $this->reviewItem($this->runWithReport(DiscoveryRunState::Reported, archived: true));
        $waiting = $this->reviewItem($this->runWithReport(DiscoveryRunState::Reported, archived: false));

        $this->migrate();

        self::assertSame(InboxItemState::Cancelled, $this->storedItem($ended)->state);
        $stored = $this->storedItem($waiting);
        self::assertSame([InboxItemState::Open, null], [$stored->state, $stored->closedAt]);
    }

    public function test_a_review_that_the_owner_dismissed_keeps_its_answer(): void
    {
        $open = $this->reviewItem($this->runWithReport(DiscoveryRunState::Reported, archived: true));
        $dismissed = $this->reviewItem($this->runWithReport(DiscoveryRunState::Reported, archived: true));
        $dismissed->state = InboxItemState::Declined;
        $dismissed->closedBy = InboxCloser::Owner;
        $dismissed->closedAt = new \DateTimeImmutable('2026-10-03 09:00:00');
        $dismissed->note = 'Later';

        $this->migrate();

        self::assertSame(InboxItemState::Cancelled, $this->storedItem($open)->state);
        $stored = $this->storedItem($dismissed);
        self::assertEquals(
            [InboxItemState::Declined, InboxCloser::Owner, null, 'Later', new \DateTimeImmutable('2026-10-03 09:00:00')],
            [$stored->state, $stored->closedBy, $stored->closeCause, $stored->note, $stored->closedAt],
        );
    }

    /** @return iterable<string, array{DiscoveryRunState, ?bool}> */
    public static function untouchedRuns(): iterable
    {
        yield 'reported, live report' => [DiscoveryRunState::Reported, false];
        yield 'reported, no report' => [DiscoveryRunState::Reported, null];
        yield 'revising, archived report' => [DiscoveryRunState::Revising, true];
        yield 'done, archived report' => [DiscoveryRunState::Done, true];
        yield 'failed, archived report' => [DiscoveryRunState::Failed, true];
    }

    #[DataProvider('untouchedRuns')]
    public function test_any_other_run_stays_as_it_is(DiscoveryRunState $state, ?bool $archived): void
    {
        $ended = $this->runWithReport(DiscoveryRunState::Reported, archived: true);
        $run = $this->runWithReport($state, $archived);

        $this->migrate();

        self::assertSame(DiscoveryRunState::Superseded, $this->storedRun($ended)->state);
        $stored = $this->storedRun($run);
        self::assertSame([$state, null], [$stored->state, $stored->endedAt]);
    }

    /** @param ?bool $archived null makes a run with no report document */
    private function runWithReport(DiscoveryRunState $state, ?bool $archived): DiscoveryRun
    {
        $run = $this->discoveryRun($this->project, $state);
        if (null !== $archived) {
            $report = new Document($this->project->owner, $this->project, 'Readiness report');
            $report->archivedAt = $archived ? new \DateTimeImmutable() : null;
            $this->em()->persist($report);
            $run->reportDocument = $report;
            $this->em()->flush();
        }

        return $run;
    }

    private function reviewItem(DiscoveryRun $run): InboxItem
    {
        $item = new InboxItem(
            project: $this->project,
            number: ++$this->itemNumber,
            kind: InboxItemKind::Review,
            title: 'Review the readiness report',
            blocking: false,
            origin: InboxAskOrigin::Loupe,
        );
        $item->discoveryRunId = $run->id;
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function storedItem(InboxItem $item): InboxItem
    {
        $this->em()->clear();

        return $this->em()->find(InboxItem::class, $item->id) ?? throw new \LogicException('The item is stored.');
    }

    private function migrate(): void
    {
        $this->em()->flush();
        $connection = $this->em()->getConnection();
        $migration = new Version20261125194856($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
