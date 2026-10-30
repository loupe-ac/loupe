<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\QueuedPushes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/** The reconcile reads the inbox set that the engine stored on each card and writes the waits of the card item. */
final class CardWaitReconcilerTest extends KernelTestCase
{
    use InboxFixtures;
    use QueuedPushes;

    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityManagerInterface $em;
    private CardWaitReconciler $reconciler;
    private Project $project;
    private Card $card;

    /** @var list<string> the types of the published inbox updates */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('mercure.hub.default', new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $type = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR)['type'] ?? null;
            if (\in_array($type, [InboxChangedPublisher::TYPE, InboxChangedPublisher::PAGE_TYPE], true)) {
                $this->published[] = $type;
            }

            return 'id';
        }));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'card-waits'), 'card-waits');
        $this->card = $this->card($em, $this->project, 12);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_stored_entry_opens_one_wait_item_held_by_a_loupe_ask(): void
    {
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A));

        $this->reconcile();

        $watch = $this->onlyWatch();
        $item = $watch->item;
        self::assertSame(InboxItemKind::Review, $item->kind);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertTrue($item->isAutomatic());
        self::assertTrue($item->blocking);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame($this->project->searchLanguage, $item->searchLanguage);
        self::assertSame([$this->card], array_map(static fn (InboxItemCard $link): Card => $link->card, array_values($item->cards->toArray())));
        self::assertEquals($this->card->id, $watch->cardId);
        self::assertSame(12, $watch->cardNumber);
        self::assertNull($watch->closedAt);

        $ask = $this->onlyAskHolding($item);
        self::assertNull($ask->sessionId);
        self::assertNull($ask->closedAt);

        $wait = $this->onlyWait($watch);
        self::assertSame('waits-for-approval', $wait->markerKey);
        self::assertSame('marker.waits_for_approval', $wait->label);
        self::assertSame(InboxCardWaitType::PullRequest, $wait->type);
        self::assertEquals($pullRequest->id, $wait->pullRequestId);
        self::assertSame(self::HEAD_A, $wait->headSha);
        self::assertNull($wait->endedAt);
    }

    public function test_a_card_with_no_open_watch_queues_one_push_for_the_ask_of_its_new_item(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->jobs()->reset();

        $this->reconcile();

        $ask = $this->onlyAskHolding($this->onlyWatch()->item);
        self::assertEquals([new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask)], $this->queuedPushes());
    }

    public function test_a_second_wait_on_a_card_that_has_an_open_watch_queues_no_push(): void
    {
        $first = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($first, self::HEAD_A));
        $this->reconcile();
        self::assertCount(1, $this->queuedPushes(), 'the first pass opened the watch');
        $this->jobs()->reset();

        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($first, self::HEAD_A), $this->pullRequestEntry($this->pullRequest(6), self::HEAD_B));
        $this->reconcile();

        self::assertCount(2, $this->onlyWatch()->waits, 'the second entry joined the open watch');
        self::assertSame([], $this->queuedPushes());
    }

    public function test_the_wait_starts_at_the_time_of_its_entry(): void
    {
        $since = new \DateTimeImmutable('2026-10-18 07:30:00');
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A, $since));

        $this->reconcile();

        self::assertEquals($since, $this->onlyWait($this->onlyWatch())->startedAt);
    }

    public function test_two_pull_requests_open_two_waits_on_the_same_item(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A), $this->pullRequestEntry($this->pullRequest(6), self::HEAD_B));

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertCount(2, $watch->waits);
        self::assertCount(2, $this->openWaits($watch));
    }

    public function test_an_entry_that_disappears_ends_its_wait_and_closes_the_item(): void
    {
        $first = $this->pullRequest(5);
        $second = $this->pullRequest(6);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($first, self::HEAD_A), $this->pullRequestEntry($second, self::HEAD_B));
        $this->reconcile();

        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($second, self::HEAD_B));
        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        $open = $this->openWaits($watch);
        self::assertCount(1, $open);
        self::assertEquals($second->id, $open[0]->pullRequestId);

        $this->storeInbox($this->em, $this->card);
        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Resolved, $watch->item->state);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::CauseEnded], [$watch->item->closedBy, $watch->item->closeCause]);
        self::assertNotNull($watch->closedAt);
        self::assertSame([], $this->openWaits($watch));
        foreach ($watch->waits as $wait) {
            self::assertSame(InboxCardWaitEndReason::Resolved, $wait->endReason);
        }
        self::assertNotNull($this->onlyAskHolding($watch->item)->closedAt);
    }

    public function test_a_new_head_commit_is_a_new_wait_on_the_same_item(): void
    {
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A));
        $this->reconcile();

        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_B));
        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertCount(2, $watch->waits);
        $open = $this->openWaits($watch);
        self::assertCount(1, $open);
        self::assertSame(self::HEAD_B, $open[0]->headSha);
    }

    public function test_a_card_nobody_read_keeps_its_open_waits_and_opens_none(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $unread = $this->card($this->em, $this->project, 13);
        $this->em->flush();
        $this->card->inboxMarkers = null;
        $this->em->flush();
        self::assertNull($unread->inboxMarkers);

        $this->reconciler->reconcile($this->project, [(string) $this->card->id, (string) $unread->id]);

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertCount(1, $this->openWaits($watch));
        self::assertSame([], $this->watches($unread->id));
    }

    public function test_a_card_nobody_read_still_closes_in_a_terminal_column(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $this->card->inboxMarkers = null;
        $this->card->column = $this->column($this->project, 'done');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Cancelled, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::CardFinished, $this->onlyWait($watch)->endReason);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::CardFinished], [$watch->item->closedBy, $watch->item->closeCause]);
    }

    public function test_an_empty_set_closes_the_open_waits(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $this->card->inboxMarkers = [];
        $this->em->flush();

        $this->reconcile();

        self::assertSame(InboxItemState::Resolved, $this->onlyWatch()->item->state);
    }

    public function test_a_card_in_a_terminal_column_closes_the_item_obsolete(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $this->card->column = $this->column($this->project, 'done');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Cancelled, $watch->item->state);
        self::assertNotNull($watch->closedAt);
        self::assertSame(InboxCardWaitEndReason::CardFinished, $this->onlyWait($watch)->endReason);
        self::assertNotNull($this->onlyAskHolding($watch->item)->closedAt);
    }

    public function test_a_deleted_card_closes_the_item_obsolete_and_the_watch_survives(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $cardId = $this->card->id;
        self::assertNotNull($cardId);
        $projectId = $this->project->id;
        $this->em->remove($this->card);
        $this->em->flush();
        $this->em->clear();
        $project = $this->em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $project);

        $this->reconciler->reconcile($project, [(string) $cardId]);

        $watch = $this->onlyWatch($cardId);
        self::assertSame(InboxItemState::Cancelled, $watch->item->state);
        self::assertNotNull($watch->closedAt);
        self::assertSame(InboxCardWaitEndReason::CardDeleted, $this->onlyWait($watch)->endReason);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::CardDeleted], [$watch->item->closedBy, $watch->item->closeCause]);
    }

    public function test_switching_the_inbox_off_closes_the_item_obsolete(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Cancelled, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::SwitchedOff, $this->onlyWait($watch)->endReason);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::SwitchedOff], [$watch->item->closedBy, $watch->item->closeCause]);
    }

    public function test_a_dismissed_key_never_reopens_and_a_new_head_opens_a_new_item(): void
    {
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A));
        $this->reconcile();
        $dismissed = $this->onlyWatch();
        $now = new \DateTimeImmutable();
        $dismissed->item->state = InboxItemState::Declined;
        $dismissed->item->closedAt = $now;
        $dismissed->dismissedAt = $now;
        $this->em->flush();

        $this->reconcile();

        self::assertSame([$dismissed], $this->watches());
        self::assertNotNull($dismissed->closedAt);
        self::assertSame(InboxCardWaitEndReason::Dismissed, $this->onlyWait($dismissed)->endReason);

        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_B));
        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame($dismissed, $watches[0]);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertSame(self::HEAD_B, $this->onlyWait($watches[1])->headSha);
    }

    public function test_an_item_closed_by_another_path_frees_the_card_for_a_new_item(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        $closed = $this->onlyWatch();
        $closed->item->state = InboxItemState::Cancelled;
        $closed->item->closedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertNotNull($closed->closedAt);
        self::assertNull($closed->dismissedAt);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($closed)->endReason);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
    }

    public function test_a_row_of_the_previous_release_stays_open_when_an_entry_covers_the_same_thing(): void
    {
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A, new \DateTimeImmutable('2026-10-18 07:30:00')));
        $this->reconcile();
        $row = $this->onlyWait($this->onlyWatch());
        $row->label = '';
        $row->params = [];
        $started = $row->startedAt;
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A, new \DateTimeImmutable('2026-10-20 09:00:00'), ['%number%' => 5]));

        $this->reconcile();

        $watch = $this->onlyWatch();
        $wait = $this->onlyWait($watch);
        self::assertSame($row, $wait);
        self::assertNull($wait->endedAt);
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertEquals($started, $wait->startedAt);
        self::assertSame('marker.waits_for_approval', $wait->label);
        self::assertSame(['%number%' => 5], $wait->params);
    }

    public function test_a_run_entry_stores_the_headline_of_the_run(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked, 'STAGE RESULT: blocked: cannot reach github.com: DNS lookup failed [reason: needs-person]. Restore the network.');
        $this->storeInbox($this->em, $this->card, $this->inboxEntry('run-needs-you', new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => $run->id?->toRfc4122() ?? ''])));

        $this->reconcile();

        $watch = $this->onlyWatch();
        $wait = $this->onlyWait($watch);
        self::assertSame(InboxCardWaitType::WorkerRun, $wait->type);
        self::assertSame('cannot reach github.com: DNS lookup failed', $wait->params['%headline%'] ?? null);
        self::assertSame('implement worker', $wait->params['%worker%'] ?? null);
        self::assertSame('Implement worker needs you: cannot reach github.com: DNS lookup failed', $watch->item->title);
        self::assertSame('The implement worker on card 12 needs you: cannot reach github.com: DNS lookup failed. Open card 12.', $watch->item->body);
    }

    public function test_a_document_entry_links_the_document_and_names_its_version(): void
    {
        $document = $this->document($this->em, $this->project);
        $document->title = 'Tech design';
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->flush();
        $this->storeInbox($this->em, $this->card, $this->documentEntry($document, 1));

        $this->reconcile();

        $watch = $this->onlyWatch();
        $wait = $this->onlyWait($watch);
        self::assertSame(InboxCardWaitType::Document, $wait->type);
        self::assertSame(1, $wait->versionNumber);
        self::assertSame([$document], array_map(static fn (InboxItemDocument $link): Document => $link->document, array_values($watch->item->documents->toArray())));
        self::assertSame('Review "Tech design", version 1', $watch->item->title);
    }

    public function test_a_document_and_a_pull_request_share_one_item_and_the_end_of_one_keeps_it_open(): void
    {
        $document = $this->document($this->em, $this->project);
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->flush();
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->documentEntry($document, 1), $this->pullRequestEntry($pullRequest, self::HEAD_A));
        $this->reconcile();
        $watch = $this->onlyWatch();
        self::assertCount(2, $this->openWaits($watch));

        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A));
        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertCount(1, $this->openWaits($watch));
    }

    public function test_the_item_is_a_review_while_a_review_wait_is_open_and_waiting_after_the_last_one_ends(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked, 'STAGE RESULT: blocked: cannot reach github.com [reason: needs-person]. Restore the network.');
        $runEntry = $this->inboxEntry('run-needs-you', new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => $run->id?->toRfc4122() ?? '']));
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $runEntry);
        $this->reconcile();
        self::assertSame(InboxItemKind::Wait, $this->onlyWatch()->item->kind);

        $this->storeInbox($this->em, $this->card, $runEntry, $this->pullRequestEntry($pullRequest, self::HEAD_A, params: ['%number%' => 5]));
        $this->reconcile();
        $item = $this->onlyWatch()->item;
        self::assertSame(InboxItemKind::Review, $item->kind);
        self::assertSame('Review pull request #5', $item->title);

        $this->storeInbox($this->em, $this->card, $runEntry);
        $this->reconcile();
        $item = $this->onlyWatch()->item;
        self::assertSame(InboxItemKind::Wait, $item->kind);
        self::assertSame('Implement worker needs you: cannot reach github.com', $item->title);
    }

    public function test_an_open_waiting_item_that_holds_a_review_wait_takes_the_kind_alone(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A, params: ['%number%' => 5]));
        $this->reconcile();
        $item = $this->onlyWatch()->item;
        $item->kind = InboxItemKind::Wait;
        $this->em->flush();
        $title = $item->title;
        $body = $item->body;

        $this->reconcile();

        $item = $this->onlyWatch()->item;
        self::assertSame(InboxItemKind::Review, $item->kind);
        self::assertSame($title, $item->title);
        self::assertSame($body, $item->body);
    }

    public function test_a_kind_change_of_an_open_item_refreshes_the_inbox_page_and_not_the_count(): void
    {
        $this->switchFlag($this->em, LiveUpdates::FLAG, true);
        $run = $this->workerRun(WorkerRunState::Blocked, 'STAGE RESULT: blocked: cannot reach github.com [reason: needs-person]. Restore the network.');
        $runEntry = $this->inboxEntry('run-needs-you', new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => $run->id?->toRfc4122() ?? '']));
        $this->storeInbox($this->em, $this->card, $runEntry);
        $this->reconcile();
        $this->publishedTypes();

        $this->storeInbox($this->em, $this->card, $runEntry, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A, params: ['%number%' => 5]));
        $this->reconcile();

        self::assertSame(InboxItemKind::Review, $this->onlyWatch()->item->kind);
        self::assertSame([InboxChangedPublisher::PAGE_TYPE], $this->publishedTypes());

        $this->reconcile();
        self::assertSame([], $this->publishedTypes());
    }

    public function test_a_closed_item_keeps_the_kind_it_had(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A, params: ['%number%' => 5]));
        $this->reconcile();

        $this->storeInbox($this->em, $this->card);
        $this->reconcile();

        $item = $this->onlyWatch()->item;
        self::assertSame(InboxItemState::Resolved, $item->state);
        self::assertSame(InboxItemKind::Review, $item->kind);
    }

    public function test_an_entry_of_the_card_opens_a_wait_beside_the_other_entries(): void
    {
        $spell = new \DateTimeImmutable('2026-10-20 09:00:00');
        $cardEntry = $this->inboxEntry('nothing-happened', MarkerSubject::card($this->card->id ?? throw new \LogicException('Flushed.'), $spell), since: $spell);
        $this->storeInbox($this->em, $this->card, $cardEntry);

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxCardWaitType::Card, $this->onlyWait($watch)->type);

        $this->storeInbox($this->em, $this->card, $cardEntry, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();

        $types = array_map(static fn (InboxCardWait $wait): InboxCardWaitType => $wait->type, $this->onlyWatch()->waits->toArray());
        self::assertCount(2, $types);
        self::assertContains(InboxCardWaitType::PullRequest, $types);
        self::assertContains(InboxCardWaitType::Card, $types);
    }

    public function test_a_second_reconcile_changes_nothing(): void
    {
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A), $this->pullRequestEntry($this->pullRequest(6), self::HEAD_B));
        $this->reconcile();
        $watch = $this->onlyWatch();
        $updatedAt = $watch->item->updatedAt;
        $body = $watch->item->body;
        $this->em->clear();
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);

        $this->reconciler->reconcile($project, [(string) $this->card->id]);

        $this->em->clear();
        $again = $this->onlyWatch();
        self::assertSame($watch->id?->toRfc4122(), $again->id?->toRfc4122());
        self::assertCount(2, $again->waits);
        self::assertCount(2, $this->openWaits($again));
        self::assertSame($body, $again->item->body);
        self::assertEquals($updatedAt, $again->item->updatedAt);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_asks WHERE project_id = :id', ['id' => (string) $this->project->id]));
    }

    public function test_an_open_wait_takes_the_label_and_values_of_its_entry_on_each_pass(): void
    {
        $pullRequest = $this->pullRequest(5);
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A, params: ['%number%' => 5]));
        $this->reconcile();

        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($pullRequest, self::HEAD_A, params: ['%number%' => 7]));
        $this->reconcile();

        self::assertSame(['%number%' => 7], $this->onlyWait($this->onlyWatch())->params);
    }

    public function test_without_a_card_list_it_finds_a_card_with_a_stored_entry_and_a_card_with_an_open_watch(): void
    {
        $second = $this->card($this->em, $this->project, 13);
        $this->em->flush();
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconciler->reconcile($this->project, null);
        $first = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $first->item->state);
        $this->storeInbox($this->em, $this->card);
        $this->storeInbox($this->em, $second, $this->pullRequestEntry($this->pullRequest(6), self::HEAD_B));

        $this->reconciler->reconcile($this->project, null);

        self::assertSame(InboxItemState::Resolved, $this->onlyWatch()->item->state);
        self::assertSame(InboxItemState::Open, $this->onlyWatch($second->id)->item->state);
    }

    public function test_a_wait_item_is_found_by_the_title_of_its_card(): void
    {
        $this->card->title = 'Launcher overlaps the footer';
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));

        $this->reconcile();

        self::assertStringNotContainsString('Launcher', $this->onlyWatch()->item->title);
        self::assertSame(1, $this->searchHits('launcher'));
    }

    public function test_a_card_rename_reindexes_its_wait_item(): void
    {
        $this->card->title = 'Launcher overlaps the footer';
        $this->storeInbox($this->em, $this->card, $this->pullRequestEntry($this->pullRequest(5), self::HEAD_A));
        $this->reconcile();
        self::assertSame(1, $this->searchHits('launcher'));

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $update(new UpdateCardCommand($this->card, Actor::Human, title: 'Sidebar hides the avatar'));

        self::assertSame(0, $this->searchHits('launcher'));
        self::assertSame(1, $this->searchHits('sidebar'));
    }

    /** @return list<string> */
    private function publishedTypes(): array
    {
        $publisher = self::getContainer()->get(LiveUpdatePublisher::class);
        self::assertInstanceOf(LiveUpdatePublisher::class, $publisher);
        $publisher->publish();
        $published = $this->published;
        $this->published = [];

        return $published;
    }

    private function reconcile(): void
    {
        $this->reconciler->reconcile($this->project, [(string) $this->card->id]);
    }

    private function pullRequest(int $number): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', $number);
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    /** @param array<string, string|int> $params */
    private function pullRequestEntry(ForgePullRequest $pullRequest, string $head, ?\DateTimeImmutable $since = null, array $params = []): \App\Module\Workflow\Contract\StoredMarker
    {
        return $this->inboxEntry('waits-for-approval', new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => $pullRequest->id?->toRfc4122() ?? '', 'head' => $head]), $params, $since);
    }

    private function workerRun(WorkerRunState $state, string $output): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: $state,
            output: $output,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /** @return list<InboxCardWatch> in item number order */
    private function watches(?Uuid $cardId = null): array
    {
        $repository = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $repository);
        $watches = $repository->findBy(['cardId' => $cardId ?? $this->card->id]);
        usort($watches, static fn (InboxCardWatch $a, InboxCardWatch $b): int => $a->item->number <=> $b->item->number);

        return $watches;
    }

    private function onlyWatch(?Uuid $cardId = null): InboxCardWatch
    {
        $watches = $this->watches($cardId);
        self::assertCount(1, $watches);

        return $watches[0];
    }

    private function onlyWait(InboxCardWatch $watch): InboxCardWait
    {
        self::assertCount(1, $watch->waits);
        $wait = $watch->waits->first();
        self::assertInstanceOf(InboxCardWait::class, $wait);

        return $wait;
    }

    /** @return list<InboxCardWait> */
    private function openWaits(InboxCardWatch $watch): array
    {
        return array_values(array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => null === $wait->endedAt));
    }

    private function onlyAskHolding(InboxItem $item): InboxAsk
    {
        $repository = self::getContainer()->get(InboxAskRepository::class);
        self::assertInstanceOf(InboxAskRepository::class, $repository);
        $asks = $repository->createQueryBuilder('a')
            ->join('a.items', 'l')
            ->andWhere('l.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();
        self::assertCount(1, $asks);
        self::assertInstanceOf(InboxAsk::class, $asks[0]);

        return $asks[0];
    }

    private function searchHits(string $word): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inbox_items WHERE project_id = :project AND origin = 'loupe' AND search_vector @@ to_tsquery('english', :word)",
            ['project' => (string) $this->project->id, 'word' => strtolower($word)],
        );
    }
}
