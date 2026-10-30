<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Driven through the real card delete, so the listener runs where Board dispatches its event. */
final class CancelInboxItemsOnCardDeletingTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'card-deleting'), 'card-deleting');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_deleted_card_cancels_its_open_agent_item_and_closes_the_ask_as_the_system(): void
    {
        $card = $this->card($this->em, $this->project);
        $item = $this->linkedItem(1, $card);
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: Uuid::v4());
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->flush();
        $cardId = $card->id;

        $this->delete($card);

        $fresh = $this->reload($item);
        self::assertSame(InboxItemState::Cancelled, $fresh->state);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::CardDeleted], [$fresh->closedBy, $fresh->closeCause]);
        self::assertNotNull($fresh->closedAt);
        self::assertNull($this->em->find(Card::class, $cardId));
        $closedAsk = $this->em->find(InboxAsk::class, $ask->id);
        self::assertInstanceOf(InboxAsk::class, $closedAsk);
        self::assertNotNull($closedAsk->closedAt);
        self::assertSame(['system'], array_column($this->askClosedEvents(), 'actor'));
    }

    public function test_the_delete_commits_when_the_closed_ask_ran_on_the_deleted_card(): void
    {
        $card = $this->card($this->em, $this->project);
        $item = $this->linkedItem(1, $card);
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: Uuid::v4());
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->persist(new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('The card has no id.'),
            cardNumber: $card->number,
            workKind: 'plan',
            state: WorkerRunState::Succeeded,
            sessionId: $ask->sessionId,
            startedAt: new \DateTimeImmutable('2026-10-18 13:00:00'),
            endedAt: new \DateTimeImmutable('2026-10-18 13:05:00'),
            exitCode: 0,
            kind: WorkerRunKind::Worker,
        ));
        $this->em->flush();
        $cardId = $card->id;

        $this->delete($card);

        self::assertNull($this->em->find(Card::class, $cardId));
        self::assertSame(InboxItemState::Cancelled, $this->reload($item)->state);
        $closedAsk = $this->em->find(InboxAsk::class, $ask->id);
        self::assertInstanceOf(InboxAsk::class, $closedAsk);
        self::assertNotNull($closedAsk->closedAt);
        self::assertNull($closedAsk->card);
        self::assertSame(['system'], array_column($this->askClosedEvents(), 'actor'));
    }

    public function test_an_item_cancels_as_card_finished_when_its_other_card_is_finished(): void
    {
        $deleted = $this->card($this->em, $this->project, 1);
        $finished = new Card(project: $this->project, column: $this->column($this->project, 'done'), title: 'Done', body: 'Body', number: 2);
        $this->em->persist($finished);
        $item = $this->linkedItem(1, $deleted, $finished);
        $this->em->flush();

        $this->delete($deleted);

        $fresh = $this->reload($item);
        self::assertSame(InboxItemState::Cancelled, $fresh->state);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::CardFinished], [$fresh->closedBy, $fresh->closeCause]);
    }

    public function test_an_item_stays_open_while_its_other_card_is_unfinished(): void
    {
        $deleted = $this->card($this->em, $this->project, 1);
        $unfinished = $this->card($this->em, $this->project, 2);
        $item = $this->linkedItem(1, $deleted, $unfinished);
        $this->em->flush();

        $this->delete($deleted);

        $fresh = $this->reload($item);
        self::assertSame(InboxItemState::Open, $fresh->state);
        self::assertNull($fresh->closeCause);
        self::assertCount(1, $fresh->cards);
    }

    public function test_a_closed_item_is_untouched(): void
    {
        $card = $this->card($this->em, $this->project);
        $item = $this->linkedItem(1, $card);
        $closedAt = new \DateTimeImmutable('2026-09-01 12:00:00');
        $item->state = InboxItemState::Resolved;
        $item->closedBy = InboxCloser::Owner;
        $item->closedAt = $closedAt;
        $this->em->flush();

        $this->delete($card);

        $fresh = $this->reload($item);
        self::assertSame(InboxItemState::Resolved, $fresh->state);
        self::assertSame(InboxCloser::Owner, $fresh->closedBy);
        self::assertNull($fresh->closeCause);
        self::assertEquals($closedAt, $fresh->closedAt);
    }

    public function test_an_item_of_another_card_is_untouched(): void
    {
        $deleted = $this->card($this->em, $this->project, 1);
        $other = $this->card($this->em, $this->project, 2);
        $item = $this->linkedItem(1, $other);
        $this->em->flush();

        $this->delete($deleted);

        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_item_of_another_project_is_untouched(): void
    {
        $deleted = $this->card($this->em, $this->project);
        $elsewhere = $this->project($this->em, $this->owner($this->em, 'card-deleting-other'), 'card-deleting-other');
        $item = $this->item($this->em, $elsewhere, 1);
        $item->cards->add(new InboxItemCard($item, $this->card($this->em, $elsewhere)));
        $this->em->flush();

        $this->delete($deleted);

        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_item_of_loupe_is_left_to_its_own_path(): void
    {
        $card = $this->card($this->em, $this->project);
        $item = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Notice, title: 'A fact', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $item->cards->add(new InboxItemCard($item, $card));
        $this->em->flush();

        $this->delete($card);

        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_the_delete_closes_nothing_while_the_inbox_is_off(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);
        $card = $this->card($this->em, $this->project);
        $item = $this->linkedItem(1, $card);
        $this->em->flush();
        $cardId = $card->id;

        $this->delete($card);

        self::assertNull($this->em->find(Card::class, $cardId));
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    private function linkedItem(int $number, Card ...$cards): InboxItem
    {
        $item = $this->item($this->em, $this->project, $number);
        foreach ($cards as $card) {
            $item->cards->add(new InboxItemCard($item, $card));
        }

        return $item;
    }

    private function delete(Card $card): void
    {
        $handler = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $handler);

        $handler(new DeleteCardCommand($card, Actor::Human));
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $fresh = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $fresh);

        return $fresh;
    }

    /** @return list<array<string, mixed>> */
    private function askClosedEvents(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = ? AND type = ? ORDER BY sequence',
            [(string) $this->project->id, InboxEventType::ASK_CLOSED],
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
