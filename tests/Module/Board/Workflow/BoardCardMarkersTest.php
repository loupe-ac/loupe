<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Workflow\BoardCardMarkers;
use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\StoredMarker;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class BoardCardMarkersTest extends KernelTestCase
{
    use ActionScenario;

    /** @var list<CardChanged> */
    private array $changes = [];

    public function test_the_card_has_no_markers_until_a_pass_stores_them(): void
    {
        $card = $this->storedCard();

        self::assertNull($card->markers);
        self::assertSame([], $this->store()->read($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_the_first_empty_sets_are_stored_and_tell_the_board_once_for_the_inbox_set(): void
    {
        $card = $this->storedCard();
        $id = $card->id ?? throw new \LogicException('A flushed card has an id.');
        self::assertNull($this->store()->readInbox($id));

        $this->write($card, []);

        self::assertSame([], $card->markers);
        self::assertSame([], $card->inboxMarkers);
        self::assertSame([], $this->store()->readInbox($id));
        self::assertCount(1, $this->changes);

        $this->changes = [];
        $this->write($card, []);
        self::assertSame([], $this->changes);
    }

    public function test_an_inbox_entry_is_stored_with_its_subject_and_read_back(): void
    {
        $card = $this->storedCard();
        $id = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $board = new StoredMarker('waits-for-approval', MarkerKind::NeedsYou, 'marker.waits_for_approval', null, ['%number%' => 4], new \DateTimeImmutable('2026-10-01 11:00:00'));
        $entry = new StoredMarker('waits-for-approval', MarkerKind::NeedsYou, 'marker.waits_for_approval', null, ['%number%' => 4], new \DateTimeImmutable('2026-10-01 11:00:00'), null, new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => '0198f6f0-0000-7000-8000-000000000001', 'head' => 'abc123']));

        $this->write($card, [$board], [$entry]);

        self::assertSame(['type' => 'pull-request', 'ids' => ['pullRequest' => '0198f6f0-0000-7000-8000-000000000001', 'head' => 'abc123']], $card->inboxMarkers[0]['subject'] ?? null);
        self::assertArrayNotHasKey('subject', $card->markers[0] ?? []);
        self::assertEquals([$entry], $this->store()->readInbox($id));
        self::assertEquals([$board], $this->store()->read($id));
    }

    public function test_a_changed_inbox_set_tells_the_board_even_when_the_board_set_is_the_same(): void
    {
        $card = $this->storedCard();
        $board = new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable('2026-10-01 11:00:00'));
        $this->write($card, [$board]);
        $this->changes = [];

        $this->write($card, [$board], [new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable('2026-10-01 11:00:00'), null, new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => '0198f6f0-0000-7000-8000-000000000002']))]);

        self::assertCount(1, $this->changes);
    }

    public function test_a_new_set_is_stored_and_read_back_and_tells_the_board_once(): void
    {
        $card = $this->storedCard();
        $marker = new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', 'marker.paused.remedy', ['%reason%' => 'move-refused', '%number%' => 4], new \DateTimeImmutable('2026-10-01 11:00:00'));

        $this->write($card, [$marker]);

        self::assertSame([['key' => 'paused', 'kind' => 'stuck', 'label' => 'marker.paused', 'remedy' => 'marker.paused.remedy', 'params' => ['%reason%' => 'move-refused', '%number%' => 4], 'since' => '2026-10-01T11:00:00+00:00']], $card->markers);
        self::assertEquals([$marker], $this->store()->read($card->id ?? throw new \LogicException('A flushed card has an id.')));
        self::assertCount(1, $this->changes);
        self::assertSame(CardChanged::UPDATED, $this->changes[0]->change);
        self::assertTrue($this->changes[0]->markersOnly);
        self::assertFalse($this->changes[0]->contentChanged);
        self::assertEquals($card->id, $this->changes[0]->cardId);
        self::assertEquals($card->project->id, $this->changes[0]->projectId);
    }

    public function test_a_link_is_stored_with_the_marker_and_read_back(): void
    {
        $card = $this->storedCard();
        $marker = new StoredMarker('no-bridge-online', MarkerKind::Stuck, 'marker.no_bridge_online', 'marker.no_bridge_online.remedy', [], new \DateTimeImmutable('2026-10-01 11:00:00'), MarkerLink::Agents);

        $this->write($card, [$marker]);

        self::assertSame('agents', $card->markers[0]['link'] ?? null);
        self::assertEquals([$marker], $this->store()->read($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_the_same_set_tells_no_board_but_the_due_time_is_always_stored(): void
    {
        $card = $this->storedCard();
        $marker = new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable('2026-10-01 11:00:00'));
        $this->write($card, [$marker]);
        $this->changes = [];

        $this->write($card, [$marker], [], new \DateTimeImmutable('2026-10-01 12:30:00'));
        self::assertSame([], $this->changes);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:30:00'), $card->markersDueAt);

        $this->write($card, [$marker], [], null);
        self::assertSame([], $this->changes);
        self::assertNull($card->markersDueAt);
    }

    public function test_a_changed_set_tells_the_board_again(): void
    {
        $card = $this->storedCard();
        $this->write($card, [new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable('2026-10-01 11:00:00'))]);
        $this->changes = [];

        $this->write($card, [new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable('2026-10-01 11:05:00'))]);
        $this->write($card, []);

        self::assertCount(2, $this->changes);
    }

    public function test_it_lists_the_open_cards_of_bound_projects_that_are_due(): void
    {
        $card = $this->storedCard();
        $project = $card->project;
        $this->bindLifecycle($project);
        $due = $this->card($project, 'next');
        $due->markers = [];
        $due->inboxMarkers = [];
        $due->markersDueAt = new \DateTimeImmutable('2026-10-01 11:00:00');
        $later = $this->card($project, 'next');
        $later->markers = [];
        $later->inboxMarkers = [];
        $later->markersDueAt = new \DateTimeImmutable('2026-10-01 13:00:00');
        $this->em()->flush();

        $ids = $this->store()->findDueCardIds(new \DateTimeImmutable('2026-10-01 12:00:00'), 500);

        self::assertContains((string) $card->id, $ids, 'a card with no inbox set is due');
        self::assertContains((string) $due->id, $ids);
        self::assertNotContains((string) $later->id, $ids, 'a card with both sets and a later due time is not due');
        self::assertSame([], $this->store()->findDueCardIds(new \DateTimeImmutable('2026-10-01 10:00:00'), 0));
        self::assertCount(1, $this->store()->findDueCardIds(new \DateTimeImmutable('2026-10-01 12:00:00'), 1));
    }

    public function test_it_lists_the_open_cards_of_the_project_whose_inbox_set_holds_an_entry(): void
    {
        $withEntry = $this->storedCard();
        $project = $withEntry->project;
        $entry = new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable('2026-10-01 11:00:00'), null, new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => '0198f6f0-0000-7000-8000-000000000002']));
        $this->write($withEntry, [], [$entry]);
        $empty = $this->card($project, 'next');
        $this->write($empty, []);
        $unread = $this->card($project, 'next');
        $finished = $this->card($project, 'done');
        $this->write($finished, [], [$entry]);
        $elsewhere = $this->card($this->workflowProject('other-markers'), 'next');
        $this->write($elsewhere, [], [$entry]);

        $ids = $this->store()->findCardIdsWithInboxEntry($project->id ?? throw new \LogicException('A flushed project has an id.'));

        self::assertSame([(string) $withEntry->id], $ids);
        self::assertNull($unread->inboxMarkers);
    }

    /**
     * @param list<StoredMarker> $markers
     * @param list<StoredMarker> $inbox
     */
    private function write(Card $card, array $markers, array $inbox = [], ?\DateTimeImmutable $dueAt = null): void
    {
        $this->store()->write($card->project->id ?? throw new \LogicException('A flushed project has an id.'), $card->id ?? throw new \LogicException('A flushed card has an id.'), $markers, $inbox, $dueAt);
    }

    private function storedCard(): Card
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('card-markers'), 'next');
        $this->service(EventDispatcherInterface::class)->addListener(CardChanged::class, function (CardChanged $change): void {
            $this->changes[] = $change;
        });

        return $card;
    }

    private function store(): BoardCardMarkers
    {
        $store = $this->service(CardMarkerStore::class);
        self::assertInstanceOf(BoardCardMarkers::class, $store);

        return $store;
    }
}
