<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\JoinInboxAskCommand;
use App\Module\Inbox\Command\JoinInboxAskHandler;
use App\Module\Inbox\Command\SubmitInboxDocumentReviewCommand;
use App\Module\Inbox\Command\SubmitInboxDocumentReviewHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\SubmitReadinessReportCommand;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Event\DiscoveryReportWaiting;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Module\Inbox\QueuedPushes;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The review item of a waiting discovery report, from the report to the verdict. */
final class DiscoveryReviewReconcilerTest extends KernelTestCase
{
    use DiscoveryScenario;
    use InboxScenario;
    use QueuedPushes;

    private Project $project;

    private DiscoveryRun $run;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setInboxFlag(true);
        $this->project = $this->workflowProject('discovery-review');
        $this->run = $this->discoveryRun($this->project);
    }

    public function test_a_report_opens_one_review_item_that_takes_a_verdict(): void
    {
        $report = $this->report();

        $items = $this->items();
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame(
            [InboxItemKind::Review, InboxAskOrigin::Loupe, 'Review the readiness report', InboxItemState::Open, false, false],
            [$item->kind, $item->origin, $item->title, $item->state, $item->blocking, $item->isAutomatic()],
        );
        self::assertEquals($this->run->id, $item->discoveryRunId);
        self::assertCount(1, $item->documents);
        self::assertSame($report, $this->reviewOf($item)->document);
        self::assertEquals([new NotifyInboxOwner((string) $item->id, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());
    }

    public function test_a_second_announcement_of_the_same_report_opens_no_second_item(): void
    {
        $report = $this->report();

        $this->announce($report);

        self::assertCount(1, $this->items());
    }

    #[TestWith(['approved', DiscoveryRunState::Done])]
    #[TestWith(['changes-requested', DiscoveryRunState::Revising])]
    public function test_a_verdict_in_the_inbox_closes_the_item_and_moves_the_run(string $verdict, DiscoveryRunState $expected): void
    {
        $report = $this->report();
        $item = $this->items()[0];
        $submit = self::getContainer()->get(SubmitInboxDocumentReviewHandler::class);
        self::assertInstanceOf(SubmitInboxDocumentReviewHandler::class, $submit);

        $submit(new SubmitInboxDocumentReviewCommand($item, $this->project->owner, $verdict, $report->currentVersion()->versionNumber, note: 'A note.'));

        self::assertSame([InboxItemState::Resolved, InboxCloser::Owner], [$item->state, $item->closedBy]);
        self::assertSame(InboxReviewVerdict::from($verdict), $this->reviewOf($item)->verdict);
        self::assertSame($expected, $this->storedRun($this->run)->state);
    }

    public function test_a_revised_report_opens_a_new_item(): void
    {
        $report = $this->report();
        $this->verdictOnThePage($report, 'changes-requested');

        $this->report();

        self::assertSame([InboxItemState::Resolved, InboxItemState::Open], array_map(static fn (InboxItem $item): InboxItemState => $item->state, $this->items()));
    }

    public function test_a_withdrawn_report_cancels_the_item_also_with_the_inbox_off(): void
    {
        $this->report();
        $this->setInboxFlag(false);

        $this->withdraw();

        $item = $this->items()[0];
        self::assertSame([InboxItemState::Cancelled, InboxCloser::Loupe, InboxCloseCause::FactEnded], [$item->state, $item->closedBy, $item->closeCause]);
        self::assertSame('A newer discovery run replaced this report.', $item->note);
    }

    public function test_the_owner_can_dismiss_the_item_and_the_report_still_waits(): void
    {
        $this->report();
        $item = $this->items()[0];
        $decline = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $decline);

        $decline(new DeclineInboxItemCommand($item, ''));

        self::assertSame(InboxItemState::Declined, $item->state);
        self::assertSame(DiscoveryRunState::Reported, $this->storedRun($this->run)->state);
    }

    /** An agent can join the item, and the session check of a withdrawal would then let it through. */
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_no_caller_withdraws_the_item(bool $joined): void
    {
        $this->report();
        $item = $this->items()[0];
        $sessionId = null;
        if ($joined) {
            $sessionId = Uuid::v7();
            $join = self::getContainer()->get(JoinInboxAskHandler::class);
            self::assertInstanceOf(JoinInboxAskHandler::class, $join);
            $join(new JoinInboxAskCommand($item, $sessionId));
        }
        $withdraw = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $withdraw);

        try {
            $withdraw(new WithdrawInboxItemCommand($item, 'Not needed.', $sessionId));
            self::fail('The report waits, so its item stays.');
        } catch (DomainErrors $error) {
            self::assertSame(['itemId' => WithdrawInboxItemHandler::REPORT_REVIEW_NOT_WITHDRAWABLE], $error->errors);
        }
        self::assertSame(InboxItemState::Open, $item->state);
    }

    /** The control of the late announcements: the same steps open an item while the report waits. */
    public function test_a_late_announcement_of_a_report_that_still_waits_opens_the_item(): void
    {
        $this->setInboxFlag(false);
        $report = $this->report();
        $this->setInboxFlag(true);

        $this->announce($report);

        self::assertCount(1, $this->items());
    }

    /** The events arrive after their commits, so the announcement of a replaced report can arrive last. */
    #[TestWith([DiscoveryRunState::Superseded])]
    #[TestWith([DiscoveryRunState::Done])]
    public function test_a_late_announcement_of_a_report_that_waits_no_more_opens_no_item(DiscoveryRunState $state): void
    {
        $this->setInboxFlag(false);
        $report = $this->report();
        $this->setInboxFlag(true);
        $this->em()->getConnection()->executeStatement('UPDATE discovery_runs SET state = ? WHERE id = ?', [$state->value, (string) $this->run->id]);

        $this->withdraw();
        $this->announce($report);

        self::assertSame([], $this->items());
    }

    public function test_the_inbox_off_opens_no_item(): void
    {
        $this->setInboxFlag(false);

        $this->report();

        self::assertSame([], $this->items());
    }

    private function report(): Document
    {
        $submit = self::getContainer()->get(SubmitReadinessReportHandler::class);
        self::assertInstanceOf(SubmitReadinessReportHandler::class, $submit);

        return $submit(new SubmitReadinessReportCommand($this->project, (string) $this->run->id, 'lifecycle', [new ReportFinding('Tests run', 'ready', 'Yes.')]));
    }

    private function verdictOnThePage(Document $report, string $verdict): void
    {
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $submit(new SubmitReviewCommand($this->project->owner, $report, $verdict, $report->currentVersion()->versionNumber, 'A note.'));
    }

    private function announce(Document $report): void
    {
        $this->dispatch(new DiscoveryReportWaiting(
            $this->project->id ?? throw new \LogicException('Stored.'),
            $this->run->id ?? throw new \LogicException('Stored.'),
            $report->id ?? throw new \LogicException('Stored.'),
        ));
    }

    private function withdraw(): void
    {
        $this->dispatch(new DiscoveryReportWithdrawn(
            $this->project->id ?? throw new \LogicException('Stored.'),
            $this->run->id ?? throw new \LogicException('Stored.'),
        ));
    }

    private function dispatch(object $event): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    /** @return list<InboxItem> */
    private function items(): array
    {
        $repository = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $repository);

        return array_values($repository->findBy(['project' => $this->project], ['number' => 'ASC']));
    }

    private function reviewOf(InboxItem $item): InboxReview
    {
        $repository = self::getContainer()->get(InboxReviewRepository::class);
        self::assertInstanceOf(InboxReviewRepository::class, $repository);

        return $repository->findOneBy(['item' => $item]) ?? throw new \LogicException('The item has a review.');
    }
}
