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
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;

final class RecordReadyToMergeOnPullRequestStateChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private ForgePullRequest $pullRequest;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->project = $this->makeProject('record-ready');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_a_read_that_turns_the_pull_request_ready_records_the_moment_and_signals_each_linked_card_once(): void
    {
        $card = $this->linkedCard();
        $doneCard = $this->linkedCard('done');
        $unlinked = $this->unlinkedCard();
        $changes = $this->cardChanges();
        $readyAt = new \DateTimeImmutable('2026-10-27 09:30:00');

        $this->read(wasReady: false, isReady: true, readySince: $readyAt);

        foreach ([$card, $doneCard] as $linked) {
            $events = $this->cardEvents($linked);
            self::assertCount(1, $events);
            self::assertSame(CardEventKind::ReadyToMerge, $events[0]->kind);
            self::assertSame(['pullRequest' => 5, 'pullRequestId' => $this->pullRequest->id?->toRfc4122()], $events[0]->detail);
            self::assertEquals($readyAt, $events[0]->occurredAt);
            $updates = array_filter($changes->getArrayCopy(), static fn (array $change): bool => [(string) $linked->id, CardChanged::UPDATED] === $change);
            self::assertCount(1, $updates, 'The ready edge tells each linked card once.');
        }
        self::assertSame([], $this->cardEvents($unlinked));
    }

    public function test_a_read_of_an_already_ready_pull_request_records_nothing_more(): void
    {
        $card = $this->linkedCard();
        $this->read(wasReady: false, isReady: true, readySince: new \DateTimeImmutable('2026-10-27 09:30:00'));

        $this->read(wasReady: true, isReady: true, readySince: new \DateTimeImmutable('2026-10-27 09:30:00'));

        self::assertCount(1, $this->cardEvents($card));
    }

    public function test_a_read_that_ends_the_ready_state_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(wasReady: true, isReady: false, readySince: null);

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_pull_request_that_turns_ready_again_records_a_second_moment(): void
    {
        $card = $this->linkedCard();
        $this->read(wasReady: false, isReady: true, readySince: new \DateTimeImmutable('2026-10-27 09:30:00'));
        $this->read(wasReady: true, isReady: false, readySince: null);

        $this->read(wasReady: false, isReady: true, readySince: new \DateTimeImmutable('2026-10-27 11:00:00'));

        self::assertCount(2, $this->cardEvents($card));
    }

    /** The row as settleReadyToMerge() leaves it, and the event Forge dispatches inside the transaction of the read. */
    private function read(bool $wasReady, bool $isReady, ?\DateTimeImmutable $readySince): void
    {
        $this->pullRequest->readyToMerge = $isReady;
        $this->pullRequest->readySince = $readySince;
        $this->em->flush();
        $event = new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(readyToMerge: $wasReady), new PullRequestSnapshot(readyToMerge: $isReady));

        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function linkedCard(string $slug = 'in-progress'): Card
    {
        $card = $this->unlinkedCard($slug);
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->flush();

        return $card;
    }

    private function unlinkedCard(string $slug = 'in-progress'): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $this->em->persist($card);
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
