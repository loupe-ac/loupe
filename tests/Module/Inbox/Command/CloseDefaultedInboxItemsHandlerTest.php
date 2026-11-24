<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Module\Inbox\Command\CloseDefaultedInboxItemsCommand;
use App\Module\Inbox\Command\CloseDefaultedInboxItemsHandler;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Inbox\Scheduler\CloseDefaultedInboxItemsTask;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\ScheduledTasks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CloseDefaultedInboxItemsHandlerTest extends KernelTestCase
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
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->project = $this->project($em, $this->owner($em, 'inbox-defaulted'), 'inbox-defaulted');
        $em->flush();
    }

    public function test_the_task_runs_every_minute_on_the_default_schedule(): void
    {
        self::assertSame('* * * * *', ScheduledTasks::cronExpressions(self::getContainer())[CloseDefaultedInboxItemsTask::class] ?? null);
    }

    public function test_an_item_past_its_deadline_is_answered_with_the_recommended_option_by_loupe(): void
    {
        $due = $this->deadlineItem(1, new \DateTimeImmutable('-1 minute'));
        $ask = $this->askHolding($due);

        $answered = $this->handler()(new CloseDefaultedInboxItemsCommand());

        self::assertSame(1, $answered);
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, $due->id);
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(InboxItemState::Resolved, $item->state);
        self::assertSame([1], $item->selectedOptions);
        self::assertSame(InboxCloser::Loupe, $item->closedBy);
        self::assertSame(InboxCloseCause::Defaulted, $item->closeCause);
        $closed = $this->em->find(InboxAsk::class, $ask->id);
        self::assertNotNull($closed?->closedAt, 'The ask closes, so the worker resumes as after an answer.');
    }

    public function test_a_default_leaves_an_entry_on_each_card_the_item_names(): void
    {
        $card = $this->card($this->em, $this->project);
        $due = $this->deadlineItem(1, new \DateTimeImmutable('-1 minute'));
        $due->cards->add(new InboxItemCard($due, $card));
        $this->askHolding($due);

        $this->handler()(new CloseDefaultedInboxItemsCommand());

        $this->em->clear();
        $entries = $this->decisionRepository()->findBy(['project' => $this->project]);
        self::assertCount(1, $entries);
        self::assertSame(InboxDecisionMadeBy::Defaulted, $entries[0]->madeBy);
        self::assertSame('ordering', $entries[0]->label);
        self::assertSame('done', $entries[0]->choice, 'The recommended option is the second one.');
        self::assertSame(['next'], $entries[0]->otherOptions);
        self::assertSame((string) $due->id, (string) $entries[0]->item?->id);
        self::assertSame((string) $card->id, (string) $entries[0]->card->id);
    }

    public function test_a_default_of_an_item_with_no_card_leaves_no_entry(): void
    {
        $due = $this->deadlineItem(1, new \DateTimeImmutable('-1 minute'));
        $this->askHolding($due);

        self::assertSame(1, $this->handler()(new CloseDefaultedInboxItemsCommand()));

        self::assertSame([], $this->decisionRepository()->findBy(['project' => $this->project]));
    }

    public function test_an_item_before_its_deadline_stays_open(): void
    {
        $waiting = $this->deadlineItem(1, new \DateTimeImmutable('+1 hour'));
        $this->askHolding($waiting);

        self::assertSame(0, $this->handler()(new CloseDefaultedInboxItemsCommand()));

        $this->em->clear();
        self::assertSame(InboxItemState::Open, $this->em->find(InboxItem::class, $waiting->id)?->state);
    }

    public function test_an_item_the_owner_already_answered_is_left_as_the_owner_answered(): void
    {
        $answered = $this->deadlineItem(1, new \DateTimeImmutable('-1 minute'));
        $answered->state = InboxItemState::Resolved;
        $answered->selectedOptions = [0];
        $answered->closedBy = InboxCloser::Owner;
        $answered->closedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame(0, $this->handler()(new CloseDefaultedInboxItemsCommand()));

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, $answered->id);
        self::assertSame([0], $item?->selectedOptions);
        self::assertSame(InboxCloser::Owner, $item->closedBy);
    }

    public function test_nothing_is_answered_while_the_inbox_is_off(): void
    {
        $due = $this->deadlineItem(1, new \DateTimeImmutable('-1 minute'));
        $this->askHolding($due);
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        self::assertSame(0, $this->handler()(new CloseDefaultedInboxItemsCommand()));
    }

    private function handler(): CloseDefaultedInboxItemsHandler
    {
        $handler = self::getContainer()->get(CloseDefaultedInboxItemsHandler::class);
        self::assertInstanceOf(CloseDefaultedInboxItemsHandler::class, $handler);

        return $handler;
    }

    private function deadlineItem(int $number, \DateTimeImmutable $defaultsAt): InboxItem
    {
        $item = $this->item($this->em, $this->project, $number);
        $item->label = 'ordering';
        $item->recommendedOption = 1;
        $item->defaultsAt = $defaultsAt;
        $this->em->flush();

        return $item;
    }

    private function askHolding(InboxItem $item): InboxAsk
    {
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: Uuid::v4());
        $this->em->persist($ask);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->flush();

        return $ask;
    }

    private function decisionRepository(): InboxDecisionRepository
    {
        $repository = self::getContainer()->get(InboxDecisionRepository::class);
        self::assertInstanceOf(InboxDecisionRepository::class, $repository);

        return $repository;
    }
}
