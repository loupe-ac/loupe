<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxActionRepositoryTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InboxActionRepository $actions;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $actions = self::getContainer()->get(InboxActionRepository::class);
        self::assertInstanceOf(InboxActionRepository::class, $actions);
        $this->actions = $actions;
    }

    public function test_an_item_lists_its_actions_oldest_first(): void
    {
        $owner = $this->owner($this->em, 'repo-order');
        $project = $this->project($this->em, $owner, 'repo-order');
        $item = $this->item($this->em, $project, 1);
        $other = $this->item($this->em, $project, 2);
        $late = new InboxAction($item, InboxActionKind::Dismiss, $owner, 'late', new \DateTimeImmutable('2026-10-13 12:00:00'));
        $early = new InboxAction($item, InboxActionKind::ResumeRun, $owner, 'early', new \DateTimeImmutable('2026-10-13 09:00:00'));
        $foreign = new InboxAction($other, InboxActionKind::Open, $owner);
        $this->persist($late, $early, $foreign);

        $found = $this->actions->findByItem($item);

        self::assertSame(['early', 'late'], array_map(static fn (InboxAction $action): string => $action->note, $found));
        self::assertSame([], $this->actions->findByItem($this->item($this->em, $project, 3)));
    }

    public function test_several_items_come_back_keyed_by_item_id(): void
    {
        $owner = $this->owner($this->em, 'repo-many');
        $project = $this->project($this->em, $owner, 'repo-many');
        $first = $this->item($this->em, $project, 1);
        $second = $this->item($this->em, $project, 2);
        $empty = $this->item($this->em, $project, 3);
        $a = new InboxAction($first, InboxActionKind::ResumeRun, $owner, 'a', new \DateTimeImmutable('2026-10-13 09:00:00'));
        $b = new InboxAction($first, InboxActionKind::Dismiss, $owner, 'b', new \DateTimeImmutable('2026-10-13 10:00:00'));
        $c = new InboxAction($second, InboxActionKind::Open, $owner, 'c');
        $this->persist($a, $b, $c);

        $byItem = $this->actions->findByItems([$first, $second, $empty]);

        self::assertSame([(string) $first->id, (string) $second->id], array_keys($byItem));
        self::assertSame(['a', 'b'], array_map(static fn (InboxAction $action): string => $action->note, $byItem[(string) $first->id]));
        self::assertSame(['c'], array_map(static fn (InboxAction $action): string => $action->note, $byItem[(string) $second->id]));
        self::assertSame([], $this->actions->findByItems([]));
    }

    public function test_one_action_is_found_only_under_its_own_item(): void
    {
        $owner = $this->owner($this->em, 'repo-one');
        $project = $this->project($this->em, $owner, 'repo-one');
        $item = $this->item($this->em, $project, 1);
        $other = $this->item($this->em, $project, 2);
        $action = new InboxAction($item, InboxActionKind::Dismiss, $owner);
        $this->persist($action);
        $id = $action->id ?? throw new \LogicException('A stored action has an id.');

        self::assertSame($action, $this->actions->findOneByIdAndItem($id, $item));
        self::assertNull($this->actions->findOneByIdAndItem($id, $other));
        self::assertNull($this->actions->findOneByIdAndItem(Uuid::v7(), $item));
    }

    public function test_actions_are_found_by_the_bridge_command_they_started(): void
    {
        $owner = $this->owner($this->em, 'repo-command');
        $project = $this->project($this->em, $owner, 'repo-command');
        $item = $this->item($this->em, $project, 1);
        $commandId = Uuid::v7();
        $otherCommandId = Uuid::v7();
        $started = new InboxAction($item, InboxActionKind::ResumeRun, $owner);
        $started->bridgeCommandId = $commandId;
        $other = new InboxAction($item, InboxActionKind::ResumeRun, $owner);
        $other->bridgeCommandId = $otherCommandId;
        $none = new InboxAction($item, InboxActionKind::Dismiss, $owner);
        $this->persist($started, $other, $none);

        self::assertSame([$started], $this->actions->findByBridgeCommandIds([$commandId, Uuid::v7()]));
        self::assertCount(2, $this->actions->findByBridgeCommandIds([$commandId, $otherCommandId]));
        self::assertSame([], $this->actions->findByBridgeCommandIds([]));
    }

    private function persist(InboxAction ...$actions): void
    {
        foreach ($actions as $action) {
            $this->em->persist($action);
        }
        $this->em->flush();
    }
}
