<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\Entity\BridgeAccountTryNow;
use App\Module\Bridge\Entity\BridgeWorkMatch;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Inbox\Entity\InboxAccountLimit;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxAccountLimitRepository;
use App\Module\Inbox\Service\AccountLimitReconciler;
use App\Module\Project\Entity\Project;
use App\Security\LoginLivenessInterface;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\QueuedPushes;
use App\Tests\Support\StubLoginLiveness;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class AccountLimitReconcilerTest extends KernelTestCase
{
    use InboxFixtures;
    use QueuedPushes;

    private const string NOW = '2026-10-16 12:00:00';
    private const string WEEK_RESET = '2026-10-17 17:00:00';
    private const string SESSION_RESET = '2026-10-16 15:00:00';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private AccountLimitReconciler $reconciler;
    private Project $project;
    private Card $card;
    private Bridge $bridge;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        self::getContainer()->set(LoginLivenessInterface::class, new StubLoginLiveness());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(AccountLimitReconciler::class);
        self::assertInstanceOf(AccountLimitReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'account-limit'), 'account-limit');
        $this->card = $this->card($em, $this->project, 12);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);

        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable(self::NOW)->modify('-10 seconds'));
        $this->bridge->name = 'laptop';
        $this->bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_TRY_NOW];
        $this->bridge->workKinds = ['implement'];
        $this->bridge->accounts = [
            ['name' => 'claude', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['implement']],
            ['name' => 'codex', 'harness' => 'codex', 'state' => 'ready', 'reason' => null, 'kinds' => ['review']],
        ];
        $em->persist($this->bridge);
        $em->flush();
    }

    public function test_a_window_at_its_hard_limit_with_waiting_work_opens_one_notice_for_the_account(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);

        $this->reconciler->reconcile($this->project);

        $episode = $this->onlyEpisode();
        $item = $episode->item;
        self::assertSame('claude', $episode->account);
        self::assertSame(InboxItemKind::Notice, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertFalse($item->blocking);
        self::assertSame('claude on laptop is at its weekly limit · 1 card waits · resets Sat 17 Oct, 17:00 UTC', $item->title);
        self::assertStringStartsWith('claude on laptop reached its usage limit.', $item->body ?? '');
        self::assertStringContainsString("- Week: resets Sat 17 Oct, 17:00 UTC\n", $item->body ?? '');
        self::assertStringContainsString('- Card 12: Ship it', $item->body ?? '');
        self::assertEquals([new NotifyInboxOwner((string) $item->id, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());
    }

    public function test_a_stored_reading_reconciles_the_notice_at_once(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(new BridgeAccountReadingsStored([(string) $this->project->id]));

        self::assertSame('claude', $this->onlyEpisode()->account);

        $this->reading('claude', 'week', 20.0, self::WEEK_RESET, limited: false, readAt: '2026-10-16 12:00:00');
        $dispatcher->dispatch(new BridgeAccountReadingsStored([(string) $this->project->id]));

        self::assertNotNull($this->onlyEpisode()->endedAt);
    }

    public function test_an_account_blocked_only_at_its_stop_value_gets_no_notice(): void
    {
        $this->reading('claude', 'week', 96.0, self::WEEK_RESET, limited: false);
        $this->waitingRequest($this->card);

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->episodes());
        self::assertSame(0, $this->itemCount());
    }

    public function test_a_limit_with_no_waiting_work_opens_no_notice(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->episodes());
    }

    public function test_each_account_at_its_limit_gets_its_own_notice(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->reading('codex', 'session', 100.0, self::SESSION_RESET, limited: true);
        $other = $this->card($this->em, $this->project, 13);
        $this->em->flush();
        $this->match($this->waitingRequest($this->card), 'claude');
        $this->match($this->waitingRequest($other), 'codex');

        $this->reconciler->reconcile($this->project);

        $episodes = $this->episodes();
        self::assertSame(['claude', 'codex'], array_map(static fn (InboxAccountLimit $episode): string => $episode->account, $episodes));
        self::assertNotSame($episodes[0]->item, $episodes[1]->item);
        self::assertSame('codex on laptop is at its session limit · 1 card waits · resets Fri 16 Oct, 15:00 UTC', $episodes[1]->item->title);
        self::assertSame(2, $this->itemCount());
    }

    public function test_accounts_of_the_same_name_on_two_bridges_each_get_their_own_notice(): void
    {
        $other = $this->secondBridge();
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->reading('claude', 'session', 100.0, self::SESSION_RESET, limited: true, bridge: $other);
        $this->waitingRequest($this->card);

        $this->reconciler->reconcile($this->project);

        $titles = [];
        foreach ($this->episodes() as $episode) {
            $titles[$episode->bridgeId->toRfc4122()] = $episode->item->title;
        }
        self::assertEquals([
            $this->bridge->id->toRfc4122() => 'claude on laptop is at its weekly limit · 1 card waits · resets Sat 17 Oct, 17:00 UTC',
            $other->id->toRfc4122() => 'claude on studio is at its session limit · 1 card waits · resets Fri 16 Oct, 15:00 UTC',
        ], $titles);
    }

    public function test_a_try_now_override_on_one_bridge_holds_only_the_notice_of_its_own_account(): void
    {
        $other = $this->secondBridge();
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true, bridge: $other);
        $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        self::assertCount(2, $this->episodes());

        $this->em->persist(new BridgeAccountTryNow(Uuid::v7(), $other, 'claude', new \DateTimeImmutable(self::NOW)));
        $this->reading('claude', 'week', 40.0, self::WEEK_RESET, limited: false, readAt: '2026-10-16 11:59:00');
        $this->reading('claude', 'week', 40.0, self::WEEK_RESET, limited: false, readAt: '2026-10-16 11:59:00', bridge: $other);
        $this->clock->sleep(60);
        $this->reconciler->reconcile($this->project);

        $open = [];
        foreach ($this->episodes() as $episode) {
            $open[$episode->bridgeId->toRfc4122()] = InboxItemState::Open === $episode->item->state;
        }
        self::assertEquals([$this->bridge->id->toRfc4122() => false, $other->id->toRfc4122() => true], $open);
    }

    public function test_the_item_lists_each_window_at_its_limit_with_its_own_reset_and_the_title_names_the_latest(): void
    {
        $this->reading('claude', 'session', 100.0, self::SESSION_RESET, limited: true);
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);

        $this->reconciler->reconcile($this->project);

        $item = $this->onlyEpisode()->item;
        self::assertSame('claude on laptop is at its weekly limit · 1 card waits · resets Sat 17 Oct, 17:00 UTC', $item->title);
        self::assertStringContainsString("- Week: resets Sat 17 Oct, 17:00 UTC\n- Session: resets Fri 16 Oct, 15:00 UTC", $item->body ?? '');
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);

        $this->reconciler->reconcile($this->project);
        $this->jobs()->reset();
        $this->reconciler->reconcile($this->project);

        self::assertCount(1, $this->episodes());
        self::assertSame(1, $this->itemCount());
        self::assertSame([], $this->queuedPushes());
    }

    public function test_a_card_that_joins_the_wait_updates_the_title_and_the_body_without_a_new_push(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        $this->jobs()->reset();

        $this->waitingRequest($this->card($this->em, $this->project, 13));
        $this->em->flush();
        $this->reconciler->reconcile($this->project);

        $item = $this->onlyEpisode()->item;
        self::assertSame('claude on laptop is at its weekly limit · 2 cards wait · resets Sat 17 Oct, 17:00 UTC', $item->title);
        self::assertStringContainsString('- Card 13:', $item->body ?? '');
        self::assertSame([], $this->queuedPushes());
    }

    public function test_a_card_that_no_longer_waits_leaves_the_title_the_body_and_the_links(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $ended = $this->waitingRequest($this->card($this->em, $this->project, 13));
        $this->reconciler->reconcile($this->project);
        self::assertStringContainsString('- Card 13:', $this->onlyEpisode()->item->body ?? '');

        $ended->state = WorkRequestState::Cancelled;
        $this->em->flush();
        $this->reconciler->reconcile($this->project);

        $item = $this->onlyEpisode()->item;
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame('claude on laptop is at its weekly limit · 1 card waits · resets Sat 17 Oct, 17:00 UTC', $item->title);
        self::assertStringContainsString('- Card 12:', $item->body ?? '');
        self::assertStringNotContainsString('- Card 13:', $item->body ?? '');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_item_cards WHERE item_id = :item', ['item' => (string) $item->id]));
    }

    public function test_the_notice_closes_as_done_when_the_account_can_take_work_again(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        $episode = $this->onlyEpisode();

        $this->clock->sleep(600);
        $this->reading('claude', 'week', 40.0, self::WEEK_RESET, limited: false, readAt: '2026-10-16 12:10:00');
        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Done, $episode->item->state);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:10:00'), $episode->endedAt);
        self::assertSame('claude on laptop could take work again at Fri 16 Oct, 12:10 UTC. It was at its limit from Fri 16 Oct, 12:00 UTC.', $episode->item->closeNote);
    }

    public function test_the_notice_is_obsolete_when_the_waiting_work_ended_before_the_account_recovered(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $request = $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        $episode = $this->onlyEpisode();

        $request->state = WorkRequestState::Cancelled;
        $this->em->flush();
        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Obsolete, $episode->item->state);
        self::assertNotNull($episode->endedAt);
    }

    public function test_the_notice_stays_open_while_a_try_now_override_lifts_the_account_and_a_new_limit_reading_keeps_the_same_item(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        $episode = $this->onlyEpisode();
        $this->jobs()->reset();

        $this->em->persist(new BridgeAccountTryNow(Uuid::v7(), $this->bridge, 'claude', new \DateTimeImmutable(self::NOW)));
        $this->em->flush();
        $this->clock->sleep(60);
        $this->reconciler->reconcile($this->project);
        self::assertSame(InboxItemState::Open, $episode->item->state, 'the override alone does not close the notice');

        // The override ran out and the account is blocked again.
        $this->clock->sleep(16 * 60);
        $this->bridge->lastSeenAt = new \DateTimeImmutable('2026-10-16 12:17:00');
        $this->em->flush();
        $this->reconciler->reconcile($this->project);

        self::assertCount(1, $this->episodes());
        self::assertSame(InboxItemState::Open, $episode->item->state);
        self::assertSame([], $this->queuedPushes());
    }

    public function test_with_the_inbox_off_the_open_notice_closes(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        $episode = $this->onlyEpisode();

        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);
        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Obsolete, $episode->item->state);
    }

    public function test_a_notice_closed_elsewhere_ends_its_episode_and_a_new_one_opens_while_the_limit_holds(): void
    {
        $this->reading('claude', 'week', 100.0, self::WEEK_RESET, limited: true);
        $this->waitingRequest($this->card);
        $this->reconciler->reconcile($this->project);
        $first = $this->onlyEpisode();
        $first->item->state = InboxItemState::Obsolete;
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertNotNull($first->endedAt);
        $open = array_values(array_filter($this->episodes(), static fn (InboxAccountLimit $episode): bool => null === $episode->endedAt));
        self::assertCount(1, $open);
        self::assertNotSame($first->item, $open[0]->item);
    }

    private function reading(string $account, string $window, ?float $usedPercent, string $resetsAt, bool $limited, string $readAt = '2026-10-16 11:00:00', ?Bridge $bridge = null): void
    {
        $utc = new \DateTimeZone('UTC');
        $this->em->persist(new BridgeAccountReading(Uuid::v7(), $bridge ?? $this->bridge, $account, $window, $usedPercent, new \DateTimeImmutable($resetsAt, $utc), $limited, 'stream', new \DateTimeImmutable($readAt, $utc)));
        $this->em->flush();
    }

    /** A second bridge of the owner with the same account names as the first. */
    private function secondBridge(): Bridge
    {
        $bridge = new Bridge($this->project->owner, Uuid::v4(), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable(self::NOW)->modify('-10 seconds'));
        $bridge->name = 'studio';
        $bridge->capabilities = $this->bridge->capabilities;
        $bridge->workKinds = $this->bridge->workKinds;
        $bridge->accounts = $this->bridge->accounts;
        $this->em->persist($bridge);
        $this->em->flush();

        return $bridge;
    }

    private function waitingRequest(Card $card): WorkRequest
    {
        $request = new WorkRequest(
            project: $card->project,
            subjectType: 'card',
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            kind: 'implement',
            capability: null,
            ruleId: 'implement-on-entry',
            createdAt: new \DateTimeImmutable('2026-10-16 11:30:00'),
        );
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function match(WorkRequest $request, string $account): void
    {
        $this->em->persist(new BridgeWorkMatch(Uuid::v7(), $this->bridge, $request, $account));
        $this->em->flush();
    }

    /** @return list<InboxAccountLimit> */
    private function episodes(): array
    {
        return array_values($this->repository()->findBy([], ['account' => 'ASC', 'id' => 'ASC']));
    }

    private function onlyEpisode(): InboxAccountLimit
    {
        $episodes = $this->episodes();
        self::assertCount(1, $episodes);

        return $episodes[0];
    }

    private function itemCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id = :project', ['project' => (string) $this->project->id]);
    }

    private function repository(): InboxAccountLimitRepository
    {
        $repository = self::getContainer()->get(InboxAccountLimitRepository::class);
        self::assertInstanceOf(InboxAccountLimitRepository::class, $repository);

        return $repository;
    }
}
