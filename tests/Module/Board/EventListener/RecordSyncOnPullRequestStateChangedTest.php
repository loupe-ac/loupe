<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecordSyncOnPullRequestStateChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Project $project;
    private ForgePullRequest $pullRequest;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-09-30 12:00:00');
        self::getContainer()->set('clock', $this->clock);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->project = $this->makeProject('record-sync');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_a_read_that_confirms_the_sync_marks_each_open_card_once(): void
    {
        $card = $this->linkedCard();
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $doneCard = $this->linkedCard('done');
        $changes = $this->cardChanges();

        $this->read(from: 'approved1', to: 'synced1', syncedSha: 'synced1');

        $events = $this->cardEvents($card);
        self::assertCount(1, $events);
        self::assertSame(CardEventKind::Synced, $events[0]->kind);
        self::assertSame(['pullRequest' => 5], $events[0]->detail);
        self::assertEquals($this->clock->now(), $events[0]->occurredAt);
        self::assertContains([(string) $card->id, CardChanged::UPDATED], $changes->getArrayCopy());

        self::assertSame([], $this->cardEvents($doneCard));
    }

    public function test_a_sync_asks_for_one_wait_reconcile_however_many_cards_link_the_pull_request(): void
    {
        $cards = [$this->linkedCard()];
        $small = $this->syncAndMeasure();

        array_push($cards, $this->linkedCard(), $this->linkedCard(), $this->linkedCard());
        $big = $this->syncAndMeasure();

        self::assertSame(1, $small['reconciles']);
        self::assertSame($small['reconciles'], $big['reconciles']);
        self::assertEqualsCanonicalizing(array_map(static fn (Card $card): string => (string) $card->id, $cards), $big['cardIds']);
    }

    public function test_a_sync_flushes_the_same_number_of_times_however_many_cards_link_the_pull_request(): void
    {
        $this->linkedCard();
        $small = $this->syncAndMeasure();

        $this->linkedCard();
        $this->linkedCard();
        $this->linkedCard();
        $big = $this->syncAndMeasure();

        self::assertSame($small['flushes'], $big['flushes']);
    }

    /** @return array{flushes: int, reconciles: int, cardIds: list<string>} */
    private function syncAndMeasure(): array
    {
        $jobs = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $jobs);
        $jobs->reset();
        $counter = new class {
            public int $flushes = 0;

            public function postFlush(): void
            {
                ++$this->flushes;
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $counter);

        $this->read(from: 'approved1', to: 'synced1', syncedSha: 'synced1');

        $this->em->getEventManager()->removeEventListener(Events::postFlush, $counter);
        $reconciles = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $jobs->getSent()),
            static fn (object $message): bool => $message instanceof ReconcileCardWaits,
        ));
        // Guard: the sync must have run, or the counts prove nothing.
        self::assertGreaterThan(0, $counter->flushes);

        return [
            'flushes' => $counter->flushes,
            'reconciles' => \count($reconciles),
            'cardIds' => array_values(array_merge(...array_map(static fn (ReconcileCardWaits $message): array => $message->cardIds ?? [], $reconciles))),
        ];
    }

    public function test_a_force_push_back_to_a_synced_head_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(from: 'pushed1', to: 'synced1', syncedSha: null);

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_head_move_that_is_not_the_sync_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(from: 'approved1', to: 'pushed1', syncedSha: null);

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_head_move_past_an_older_sync_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(from: 'synced1', to: 'pushed1', syncedSha: 'synced1');

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_later_read_of_the_same_synced_head_records_nothing_more(): void
    {
        $card = $this->linkedCard();
        $this->read(from: 'approved1', to: 'synced1', syncedSha: 'synced1');

        $this->read(from: 'synced1', to: 'synced1', syncedSha: 'synced1');

        self::assertCount(1, $this->cardEvents($card));
    }

    /** The row as apply() leaves it, and the event Forge dispatches inside the transaction of the read. */
    private function read(string $from, string $to, ?string $syncedSha): void
    {
        $this->pullRequest->headSha = $to;
        $this->pullRequest->syncedSha = $syncedSha;
        $this->em->flush();
        $event = new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(headSha: $from), new PullRequestSnapshot(headSha: $to));

        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function linkedCard(string $slug = 'in-progress'): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $this->em->persist($card);
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->flush();

        return $card;
    }

    /** @return list<CardEvent> */
    private function cardEvents(Card $card): array
    {
        $cardEvents = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $cardEvents);

        return $cardEvents->findForCard($card);
    }

    /** @return \ArrayObject<int, array{string, string}> */
    private function cardChanges(): \ArrayObject
    {
        /** @var \ArrayObject<int, array{string, string}> $changes */
        $changes = new \ArrayObject();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(SymfonyEventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use ($changes): void {
            $changes[] = [(string) $event->cardId, $event->change];
        });

        return $changes;
    }
}
