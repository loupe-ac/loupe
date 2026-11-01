<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeClaimRefusal;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxBridgeOutage;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxBridgeOutageRepository;
use App\Module\Inbox\Service\BridgeOutageReconciler;
use App\Module\Project\Entity\Project;
use App\Security\LoginLivenessInterface;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\QueuedPushes;
use App\Tests\Support\StubLoginLiveness;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class BridgeOutageReconcilerTest extends KernelTestCase
{
    use InboxFixtures;
    use QueuedPushes;

    private const string NOW = '2026-10-16 12:00:00';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private BridgeOutageReconciler $reconciler;
    private Project $project;
    private Card $card;

    private StubLoginLiveness $logins;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->logins = new StubLoginLiveness();
        self::getContainer()->set(LoginLivenessInterface::class, $this->logins);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(BridgeOutageReconciler::class);
        self::assertInstanceOf(BridgeOutageReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'bridge-outage'), 'bridge-outage');
        $this->card = $this->card($em, $this->project, 12);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_work_that_waited_ten_minutes_with_no_bridge_opens_one_notice_that_holds_its_card(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');

        $this->reconciler->reconcile($this->project);

        $outage = $this->onlyOutage();
        $item = $outage->item;
        self::assertSame(InboxItemKind::Notice, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertFalse($item->blocking);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertSame('No bridge can take work', $item->title);
        self::assertSame("No bridge is online that can take the work of these cards:\n\n- Card 12: Ship it\n\nStart a bridge, or run `loupe login`.", $item->body);
        self::assertSame($this->project->searchLanguage, $item->searchLanguage);
        self::assertSame([$this->card], $this->cardsOf($item));
        self::assertEquals(new \DateTimeImmutable('2026-10-16 11:49:00'), $outage->startedAt);
        self::assertNull($outage->endedAt);
        self::assertSame(1, $this->searchHits('bridge'));
    }

    public function test_a_new_notice_queues_one_push_and_a_card_joining_it_queues_none(): void
    {
        $this->jobs()->reset();
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');

        $this->reconciler->reconcile($this->project);

        $item = $this->onlyOutage()->item;
        self::assertEquals([new NotifyInboxOwner((string) $item->id, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());

        $this->waitingRequest($this->card($this->em, $this->project, 9), '2026-10-16 11:55:00');
        $this->em->flush();
        $this->reconciler->reconcile($this->project);

        self::assertCount(1, $this->queuedPushes(), 'the second pass only extended the open notice');
    }

    public function test_work_that_waited_less_than_ten_minutes_opens_nothing_until_the_tenth_minute_passes(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:50:01');

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->outages());
        self::assertSame(0, $this->itemCount());

        $this->clock->sleep(1);
        $this->reconciler->reconcile($this->project);

        self::assertCount(1, $this->outages());
        self::assertSame(1, $this->itemCount());
    }

    public function test_a_paused_or_an_online_bridge_that_can_take_the_work_opens_nothing(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $bridge = $this->bridge(seenSecondsAgo: 10);
        $bridge->pausedReported = true;
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertSame(0, $this->itemCount());

        $bridge->pausedReported = false;
        $this->em->flush();
        $this->reconciler->reconcile($this->project);

        self::assertSame(0, $this->itemCount());

        // The same request opens the notice once the bridge is quiet and ten minutes passed.
        $this->clock->sleep(3600);
        $this->reconciler->reconcile($this->project);

        self::assertSame(1, $this->itemCount());
    }

    public function test_a_card_whose_work_starts_to_wait_later_joins_the_open_notice_before_its_own_ten_minutes_pass(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $item = $this->onlyOutage()->item;
        $number = $item->number;
        $other = $this->card($this->em, $this->project, 9);
        $this->waitingRequest($other, '2026-10-16 11:55:00');
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        $outage = $this->onlyOutage();
        self::assertSame($item, $outage->item);
        self::assertSame($number, $outage->item->number);
        self::assertSame(1, $this->itemCount());
        self::assertSame([$other, $this->card], $this->cardsOf($outage->item));
        self::assertSame("No bridge is online that can take the work of these cards:\n\n- Card 9: Ship it\n- Card 12: Ship it\n\nStart a bridge, or run `loupe login`.", $outage->item->body);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 11:49:00'), $outage->startedAt);
    }

    public function test_the_notice_lists_every_waiting_card_when_it_opens(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $other = $this->card($this->em, $this->project, 9);
        $this->waitingRequest($other, '2026-10-16 11:55:00');
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        $outage = $this->onlyOutage();
        self::assertSame([$other, $this->card], $this->cardsOf($outage->item));
        self::assertSame("No bridge is online that can take the work of these cards:\n\n- Card 9: Ship it\n- Card 12: Ship it\n\nStart a bridge, or run `loupe login`.", $outage->item->body);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 11:49:00'), $outage->startedAt);
    }

    public function test_a_card_that_waited_less_than_ten_minutes_opens_nothing_when_the_due_card_is_deleted(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $other = $this->card($this->em, $this->project, 9);
        $this->waitingRequest($other, '2026-10-16 11:55:00');
        $this->em->remove($this->card);
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->outages());
        self::assertSame(0, $this->itemCount());
    }

    public function test_a_second_reconcile_changes_nothing_and_each_project_gets_its_own_notice(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $second = $this->project($this->em, $this->project->owner, 'bridge-outage-second');
        $secondCard = $this->card($this->em, $second, 3);
        $this->waitingRequest($secondCard, '2026-10-16 11:40:00');
        $this->em->flush();

        $this->reconciler->reconcile($this->project);
        $this->reconciler->reconcile($this->project);
        $this->reconciler->reconcile($second);
        $this->reconciler->reconcile($second);

        $outages = $this->outages();
        self::assertCount(2, $outages);
        self::assertSame(2, $this->itemCount());
        self::assertSame([1], array_values(array_unique(array_map(static fn (InboxBridgeOutage $outage): int => $outage->item->number, $outages))));
        self::assertEqualsCanonicalizing([$this->project->id, $second->id], array_map(static fn (InboxBridgeOutage $outage): ?Uuid => $outage->project->id, $outages));
    }

    public function test_the_notice_closes_done_with_a_note_once_a_bridge_can_take_the_work(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $this->bridge(seenSecondsAgo: 5);
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        $outage = $this->onlyOutage();
        $item = $outage->item;
        self::assertSame(InboxItemState::Resolved, $item->state);
        self::assertNull($item->note);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::FactEnded], [$item->closedBy, $item->closeCause]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $outage->endedAt);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $item->closedAt);
        self::assertSame([], $this->openOutages());
    }

    public function test_the_notice_closes_done_when_a_bridge_claimed_the_work(): void
    {
        $request = $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $request->state = WorkRequestState::Claimed;
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Resolved, $this->onlyOutage()->item->state);
    }

    public function test_the_notice_closes_done_when_the_only_bridge_that_can_take_the_work_is_paused(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $this->bridge(seenSecondsAgo: 5)->pausedReported = true;
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Resolved, $this->onlyOutage()->item->state);
    }

    public function test_the_notice_closes_obsolete_when_the_waiting_work_ended_with_no_bridge_back(): void
    {
        $request = $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable(self::NOW));
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        $outage = $this->onlyOutage();
        self::assertSame(InboxItemState::Cancelled, $outage->item->state);
        self::assertNull($outage->item->note);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::FactEnded], [$outage->item->closedBy, $outage->item->closeCause]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $outage->endedAt);
    }

    public function test_the_notice_closes_obsolete_while_the_inbox_is_off(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Cancelled, $this->onlyOutage()->item->state);
        self::assertSame(1, $this->itemCount());
    }

    public function test_the_inbox_off_opens_no_notice(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $this->reconciler->reconcile($this->project);

        self::assertSame(0, $this->itemCount());
        self::assertSame([], $this->outages());
    }

    public function test_a_later_outage_opens_a_new_notice_after_the_first_closed(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $this->bridge(seenSecondsAgo: 5);
        $this->em->flush();
        $this->reconciler->reconcile($this->project);
        self::assertSame([], $this->openOutages());

        // The bridge turns quiet, and the work waits ten minutes more.
        $this->clock->sleep(3600);
        $this->reconciler->reconcile($this->project);

        $outages = $this->outages();
        self::assertCount(2, $outages);
        self::assertSame(2, $this->itemCount());
        $open = $this->openOutages();
        self::assertCount(1, $open);
        self::assertSame(2, $open[0]->item->number);
    }

    public function test_an_outage_whose_notice_was_closed_elsewhere_ends_and_a_new_one_opens_while_work_waits(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->reconciler->reconcile($this->project);
        $first = $this->onlyOutage();
        $first->item->state = InboxItemState::Cancelled;
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $first->endedAt);
        $open = $this->openOutages();
        self::assertCount(1, $open);
        self::assertNotSame($first, $open[0]);
        self::assertSame(2, $open[0]->item->number);
    }

    public function test_a_card_deleted_before_the_notice_opens_leaves_nothing_to_open(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:49:00');
        $this->em->remove($this->card);
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->outages());
        self::assertSame(0, $this->itemCount());
    }

    public function test_a_notice_names_the_expired_login_and_follows_the_login_as_it_expires_and_returns(): void
    {
        $login = Uuid::v7();
        $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $bridge = $this->bridge(seenSecondsAgo: 3600);
        $bridge->loginId = $login;
        $bridge->name = 'macbookpro';
        $this->em->flush();

        $this->reconciler->reconcile($this->project);
        $item = $this->onlyOutage()->item;
        self::assertStringContainsString('Start a bridge, or run `loupe login`.', $this->bodyOf($item));

        $this->logins->expired = [$login->toRfc4122()];
        $this->clock->sleep(60);
        $this->reconciler->reconcile($this->project);
        self::assertStringContainsString('The login of macbookpro expired. Run `loupe login` on that machine.', $this->bodyOf($item));
        self::assertStringNotContainsString('Start a bridge', $this->bodyOf($item));
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:01:00'), $item->updatedAt);

        $this->logins->expired = [];
        $this->reconciler->reconcile($this->project);
        self::assertStringContainsString('Start a bridge, or run `loupe login`.', $this->bodyOf($item));
    }

    public function test_a_notice_names_the_expired_login_while_another_online_bridge_cannot_take_the_work(): void
    {
        $login = Uuid::v7();
        $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $quiet = $this->bridge(seenSecondsAgo: 3600);
        $quiet->loginId = $login;
        $quiet->name = 'macbookpro';
        $online = $this->bridge(seenSecondsAgo: 10);
        $online->workKinds = ['review'];
        $this->em->flush();
        $this->logins->expired = [$login->toRfc4122()];

        $this->reconciler->reconcile($this->project);

        $body = $this->bodyOf($this->onlyOutage()->item);
        self::assertStringContainsString('The login of macbookpro expired. Run `loupe login` on that machine.', $body);
        self::assertStringNotContainsString('Start a bridge', $body);
    }

    public function test_a_notice_asks_for_a_login_on_each_machine_when_several_logins_expired(): void
    {
        $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $expired = [];
        foreach (['macbookpro', 'studio'] as $name) {
            $login = Uuid::v7();
            $bridge = $this->bridge(seenSecondsAgo: 3600);
            $bridge->loginId = $login;
            $bridge->name = $name;
            $expired[] = $login->toRfc4122();
        }
        $this->em->flush();
        $this->logins->expired = $expired;

        $this->reconciler->reconcile($this->project);

        $body = $this->bodyOf($this->onlyOutage()->item);
        self::assertStringContainsString('The logins of macbookpro, studio expired. Run `loupe login` on each of those machines.', $body);
        self::assertStringNotContainsString('that machine', $body);
    }

    public function test_a_notice_tells_the_owner_to_update_a_bridge_whose_old_cli_names_no_account(): void
    {
        $request = $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $bridge = $this->bridge(seenSecondsAgo: 10);
        $bridge->name = 'macbookpro';
        $bridge->cliVersion = '1.32.0';
        $this->refuse($bridge, $request, null);

        $this->reconciler->reconcile($this->project);

        $body = $this->bodyOf($this->onlyOutage()->item);
        self::assertStringContainsString('macbookpro runs a CLI that names no account in its claims. Run `loupe update` on that machine.', $body);
        self::assertStringNotContainsString('Start a bridge', $body);
    }

    public function test_a_notice_names_the_account_that_the_server_refused(): void
    {
        $request = $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $bridge = $this->bridge(seenSecondsAgo: 10);
        $bridge->name = 'macbookpro';
        $this->refuse($bridge, $request, 'work');

        $this->reconciler->reconcile($this->project);

        $body = $this->bodyOf($this->onlyOutage()->item);
        self::assertStringContainsString('The server refused macbookpro for the account work. Check the account of the work entry or the experiment variant in `rules.yaml`, then run `loupe bridge reload`.', $body);
        self::assertStringNotContainsString('loupe update', $body);
    }

    public function test_a_notice_for_a_current_cli_that_named_no_account_points_at_the_work_entry(): void
    {
        $request = $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $bridge = $this->bridge(seenSecondsAgo: 10);
        $bridge->name = 'macbookpro';
        $bridge->cliVersion = '1.33.0';
        $this->refuse($bridge, $request, null);

        $this->reconciler->reconcile($this->project);

        $body = $this->bodyOf($this->onlyOutage()->item);
        self::assertStringContainsString('The server refused macbookpro because its claim named no account. Check the work entry in `rules.yaml`, then run `loupe bridge reload`.', $body);
        self::assertStringNotContainsString('loupe update', $body);
    }

    public function test_a_notice_lists_a_refused_bridge_once_for_all_the_requests_it_was_refused(): void
    {
        $other = $this->card($this->em, $this->project, 13);
        $this->em->flush();
        $first = $this->waitingRequest($this->card, '2026-10-16 11:00:00');
        $second = $this->waitingRequest($other, '2026-10-16 11:00:00');
        $bridge = $this->bridge(seenSecondsAgo: 10);
        $bridge->name = 'macbookpro';
        $this->refuse($bridge, $first, 'work');
        $this->refuse($bridge, $second, 'work');

        $this->reconciler->reconcile($this->project);

        self::assertSame(1, substr_count($this->bodyOf($this->onlyOutage()->item), 'The server refused macbookpro'));
    }

    private function refuse(Bridge $bridge, WorkRequest $request, ?string $account): void
    {
        $this->em->persist(new BridgeClaimRefusal(Uuid::v7(), $bridge, $request, $account));
        $this->em->flush();
    }

    private function bodyOf(InboxItem $item): string
    {
        return $item->body ?? throw new \LogicException('A notice has a body.');
    }

    private function waitingRequest(Card $card, string $createdAt): WorkRequest
    {
        $request = new WorkRequest(
            project: $card->project,
            subjectType: 'card',
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            kind: 'implement',
            capability: null,
            ruleId: 'implement-on-entry',
            createdAt: new \DateTimeImmutable($createdAt),
        );
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function bridge(int $seenSecondsAgo): Bridge
    {
        $bridge = new Bridge($this->project->owner, Uuid::v4(), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d seconds', $seenSecondsAgo)));
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $bridge->workKinds = ['implement'];
        $this->em->persist($bridge);
        $this->em->flush();

        return $bridge;
    }

    /** @return list<InboxBridgeOutage> */
    private function outages(): array
    {
        return array_values($this->repository()->findBy([], ['startedAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<InboxBridgeOutage> */
    private function openOutages(): array
    {
        return array_values(array_filter($this->outages(), static fn (InboxBridgeOutage $outage): bool => null === $outage->endedAt));
    }

    private function onlyOutage(): InboxBridgeOutage
    {
        $outages = $this->outages();
        self::assertCount(1, $outages);

        return $outages[0];
    }

    private function itemCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id IN (SELECT id FROM projects WHERE owner_id = :owner)', ['owner' => (string) $this->project->owner->id]);
    }

    /** @return list<Card> */
    private function cardsOf(InboxItem $item): array
    {
        $cards = array_map(static fn (InboxItemCard $link): Card => $link->card, array_values($item->cards->toArray()));
        usort($cards, static fn (Card $a, Card $b): int => $a->number <=> $b->number);

        return $cards;
    }

    private function searchHits(string $query): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inbox_items WHERE project_id = :project AND search_vector @@ websearch_to_tsquery('english', :query)",
            ['project' => (string) $this->project->id, 'query' => $query],
        );
    }

    private function repository(): InboxBridgeOutageRepository
    {
        $repository = self::getContainer()->get(InboxBridgeOutageRepository::class);
        self::assertInstanceOf(InboxBridgeOutageRepository::class, $repository);

        return $repository;
    }
}
