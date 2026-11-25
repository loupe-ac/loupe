<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SupersedeDiscoveryOnReportArchivedTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    /** @var list<array{string, string, int}> */
    private array $withdrawn = [];

    /** @var list<string> */
    private array $statusChanged = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-report-archived');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $connection = $this->em()->getConnection();
        $dispatcher->addListener(DiscoveryReportWithdrawn::class, function (DiscoveryReportWithdrawn $event) use ($connection): void {
            $this->withdrawn[] = [(string) $event->projectId, (string) $event->runId, $connection->getTransactionNestingLevel()];
        });
        $dispatcher->addListener(DocumentStatusChanged::class, function (DocumentStatusChanged $event): void {
            $this->statusChanged[] = (string) $event->documentId;
        });
    }

    public function test_an_archived_report_ends_its_reported_run_and_withdraws_it(): void
    {
        $run = $this->runWithReport(DiscoveryRunState::Reported);
        $other = $this->runWithReport(DiscoveryRunState::Reported, '2026-10-02 11:00:00');
        $outside = $this->em()->getConnection()->getTransactionNestingLevel();

        $this->archive($run);

        // Inside a transaction, so a message that a listener queues commits with the new state.
        self::assertSame([[(string) $this->project->id, (string) $run->id, $outside + 1]], $this->withdrawn);
        $stored = $this->storedRun($run);
        self::assertSame(DiscoveryRunState::Superseded, $stored->state);
        self::assertNotNull($stored->endedAt);
        self::assertEqualsWithDelta(time(), $stored->endedAt->getTimestamp(), 60);
        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($other)->state);
    }

    /** @return iterable<string, array{DiscoveryRunState}> */
    public static function otherStates(): iterable
    {
        yield 'requested' => [DiscoveryRunState::Requested];
        yield 'revising' => [DiscoveryRunState::Revising];
        yield 'failed' => [DiscoveryRunState::Failed];
        yield 'done' => [DiscoveryRunState::Done];
        yield 'superseded' => [DiscoveryRunState::Superseded];
    }

    #[DataProvider('otherStates')]
    public function test_an_archived_report_leaves_a_run_that_is_not_reported(DiscoveryRunState $state): void
    {
        $run = $this->runWithReport($state);

        $this->archive($run);

        self::assertSame([(string) $run->reportDocument?->id], $this->statusChanged);
        self::assertSame([], $this->withdrawn);
        $stored = $this->storedRun($run);
        self::assertSame([$state, null], [$stored->state, $stored->endedAt]);
    }

    /** A verdict that committed after the run was loaded wins over the loaded state. */
    public function test_the_listener_reads_the_state_of_the_row(): void
    {
        $run = $this->runWithReport(DiscoveryRunState::Reported);
        $this->em()->getConnection()->executeStatement("UPDATE discovery_runs SET state = 'done' WHERE id = ?", [(string) $run->id]);

        $this->archive($run);

        self::assertCount(1, $this->statusChanged);
        self::assertSame([], $this->withdrawn);
        self::assertSame(DiscoveryRunState::Done, $this->storedRun($run)->state);
    }

    public function test_an_archived_document_that_is_no_report_changes_no_run(): void
    {
        $run = $this->runWithReport(DiscoveryRunState::Reported);
        $document = new Document($this->project->owner, $this->project, 'Notes');
        $this->em()->persist($document);
        $this->em()->flush();

        self::getContainer()->get(ArchiveDocumentHandler::class)(new ArchiveDocumentCommand($document, 'Old notes'));

        self::assertSame([(string) $document->id], $this->statusChanged);
        self::assertSame([], $this->withdrawn);
        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($run)->state);
    }

    public function test_a_tag_change_on_a_live_report_keeps_the_run_reported(): void
    {
        $run = $this->runWithReport(DiscoveryRunState::Reported);
        $report = $run->reportDocument ?? throw new \LogicException('The run has a report.');

        self::getContainer()->get(SetDocumentTagsHandler::class)(new SetDocumentTagsCommand($report, ['readiness']));

        self::assertSame([(string) $report->id], $this->statusChanged);
        self::assertSame([], $this->withdrawn);
        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($run)->state);
    }

    private function runWithReport(DiscoveryRunState $state, string $createdAt = '2026-10-02 12:00:00'): DiscoveryRun
    {
        $report = new Document($this->project->owner, $this->project, 'Readiness report');
        $this->em()->persist($report);
        $run = $this->discoveryRun($this->project, $state, $createdAt);
        $run->reportDocument = $report;
        $this->em()->flush();

        return $run;
    }

    private function archive(DiscoveryRun $run): void
    {
        $report = $run->reportDocument ?? throw new \LogicException('The run has a report.');
        self::getContainer()->get(ArchiveDocumentHandler::class)(new ArchiveDocumentCommand($report, 'Not needed'));
    }
}
