<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

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
