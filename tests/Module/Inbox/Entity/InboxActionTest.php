<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxActionTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_an_action_starts_with_a_note_that_is_empty_and_no_links(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->owner($em, 'action-new');
        $item = $this->item($em, $this->project($em, $owner, 'action-new'));

        $action = new InboxAction($item, InboxActionKind::ResumeRun, $owner);

        self::assertSame($item, $action->item);
        self::assertSame('', $action->note);
        self::assertNull($action->bridgeCommandId);
        self::assertNull($action->workRequestId);
        self::assertNull($action->pauseId);
        self::assertNull($action->cancelledAt);
        self::assertSame(2000, InboxAction::MAX_NOTE_LENGTH);
    }

    public function test_an_action_round_trips_through_the_database(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->owner($em, 'action-round');
        $item = $this->item($em, $this->project($em, $owner, 'action-round'));
        $commandId = Uuid::v7();
        $requestId = Uuid::v7();
        $pauseId = Uuid::v7();

        $action = new InboxAction($item, InboxActionKind::SendAgent, $owner, 'Try again', new \DateTimeImmutable('2026-10-13 10:00:00'));
        $action->bridgeCommandId = $commandId;
        $action->workRequestId = $requestId;
        $action->pauseId = $pauseId;
        $action->cancelledAt = new \DateTimeImmutable('2026-10-13 10:05:00');
        $em->persist($action);
        $em->flush();
        $id = $action->id;
        $em->clear();

        $found = $em->find(InboxAction::class, $id);

        self::assertInstanceOf(InboxAction::class, $found);
        self::assertSame(InboxActionKind::SendAgent, $found->kind);
        self::assertSame('Try again', $found->note);
        self::assertTrue($commandId->equals($found->bridgeCommandId));
        self::assertTrue($requestId->equals($found->workRequestId));
        self::assertTrue($pauseId->equals($found->pauseId));
        self::assertSame('2026-10-13 10:05:00', $found->cancelledAt?->format('Y-m-d H:i:s'));
        self::assertSame($owner->id?->toRfc4122(), $found->requestedBy?->id?->toRfc4122());
    }

    public function test_every_kind_value_is_spelled_in_full_and_fits_the_column(): void
    {
        self::assertSame(
            ['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'run-command', 'dismiss', 'add-entry', 'open-pull-request', 'approve-pull-request', 'hold-card', 'answer', 'complete', 'submit-review'],
            array_map(static fn (InboxActionKind $kind): string => $kind->value, InboxActionKind::cases()),
        );
        foreach (InboxActionKind::cases() as $kind) {
            self::assertLessThanOrEqual(20, \strlen($kind->value));
        }
    }

    public function test_the_inbox_action_cause_fits_the_cause_column(): void
    {
        self::assertSame('inbox-action', BridgeCommandCause::InboxAction->value);
        self::assertLessThanOrEqual(20, \strlen(BridgeCommandCause::InboxAction->value));
    }

    public function test_deleting_the_item_deletes_its_actions(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->owner($em, 'action-cascade');
        $item = $this->item($em, $this->project($em, $owner, 'action-cascade'));
        $action = new InboxAction($item, InboxActionKind::Dismiss, $owner);
        $em->persist($action);
        $em->flush();
        $id = (string) $action->id;

        $em->getConnection()->executeStatement('DELETE FROM inbox_items WHERE id = :id', ['id' => (string) $item->id]);

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_actions WHERE id = :id', ['id' => $id]));
    }

    public function test_deleting_the_requester_keeps_the_action_with_no_requester(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->owner($em, 'action-owner');
        $other = $this->owner($em, 'action-requester');
        $item = $this->item($em, $this->project($em, $owner, 'action-requester'));
        $action = new InboxAction($item, InboxActionKind::Open, $other);
        $em->persist($action);
        $em->flush();
        $id = (string) $action->id;

        $em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $other->id]);

        self::assertNull($em->getConnection()->fetchOne('SELECT requested_by_id FROM inbox_actions WHERE id = :id', ['id' => $id]));
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_actions WHERE id = :id', ['id' => $id]));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
