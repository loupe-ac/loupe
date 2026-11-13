<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Security\ApiKeyActor;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WithdrawInboxItemHandlerTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;
    private WithdrawInboxItemHandler $withdraw;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $withdraw = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $withdraw);
        $this->withdraw = $withdraw;
        $this->project = $this->inboxProject($em, $this->signedUpUser($em, 'inbox-withdraw-handler'));
    }

    public function test_a_key_withdraws_an_ask_of_any_session_and_the_item_names_the_key(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->askHolding($this->em, $this->project, [$item]);
        $key = new ApiKeyActor(Uuid::v7(), 'Weekly CI');

        ($this->withdraw)(new WithdrawInboxItemCommand($item, '  The pull request merged  ', null, $key));

        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        self::assertSame(InboxItemState::Cancelled, $stored->state);
        self::assertSame([InboxCloser::OwnerThroughApiKey, InboxCloseCause::Withdrawn], [$stored->closedBy, $stored->closeCause]);
        self::assertSame('The pull request merged', $stored->note);
        self::assertSame([(string) $key->keyId, 'Weekly CI'], [(string) $stored->closedApiKeyId, $stored->closedApiKeyName]);
        self::assertNotNull($stored->closedAt);
    }

    public function test_a_sign_in_that_holds_no_key_still_withdraws_and_records_no_key(): void
    {
        $item = $this->question($this->em, $this->project, 1);

        ($this->withdraw)(new WithdrawInboxItemCommand($item, 'Settled', null));

        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertSame(InboxCloser::OwnerThroughApiKey, $stored?->closedBy);
        self::assertNull($stored->closedApiKeyId);
        self::assertNull($stored->closedApiKeyName);
    }

    public function test_an_agent_session_closes_as_the_agent_and_keeps_the_session_match(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $ask = $this->askHolding($this->em, $this->project, [$item]);

        self::assertSame(['sessionId' => WithdrawInboxItemHandler::NOT_ASKING_SESSION], $this->refusal(new WithdrawInboxItemCommand($item, 'Settled', Uuid::v4())));
        self::assertSame(InboxItemState::Open, $item->state);

        ($this->withdraw)(new WithdrawInboxItemCommand($item, 'Settled', $ask->sessionId));

        $stored = $this->reload($item);
        self::assertSame([InboxCloser::Agent, InboxCloseCause::Withdrawn], [$stored->closedBy, $stored->closeCause]);
        self::assertNull($stored->closedApiKeyId);
    }

    public function test_a_key_does_not_name_itself_on_a_session_withdrawal(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $ask = $this->askHolding($this->em, $this->project, [$item]);

        ($this->withdraw)(new WithdrawInboxItemCommand($item, 'Settled', $ask->sessionId, new ApiKeyActor(Uuid::v7(), 'Weekly CI')));

        self::assertNull($this->reload($item)->closedApiKeyName);
    }

    /** @return iterable<string, array{InboxItemKind, InboxAskOrigin, bool, string}> */
    public static function itemsNoOneWithdraws(): iterable
    {
        yield 'a wait that Loupe opened' => [InboxItemKind::Wait, InboxAskOrigin::Loupe, true, WithdrawInboxItemHandler::WAIT_NOT_WITHDRAWABLE];
        yield 'a notice' => [InboxItemKind::Notice, InboxAskOrigin::Agent, false, WithdrawInboxItemHandler::NOTICE_NOT_WITHDRAWABLE];
        yield 'a workflow question' => [InboxItemKind::Workflow, InboxAskOrigin::Agent, true, WithdrawInboxItemHandler::WORKFLOW_NOT_WITHDRAWABLE];
    }

    #[DataProvider('itemsNoOneWithdraws')]
    public function test_a_key_is_refused_what_no_caller_withdraws(InboxItemKind $kind, InboxAskOrigin $origin, bool $blocking, string $key): void
    {
        $item = new InboxItem(project: $this->project, number: 1, kind: $kind, title: 'Look', blocking: $blocking, origin: $origin);
        $this->em->persist($item);
        $this->em->flush();

        self::assertSame(['itemId' => $key], $this->refusal(new WithdrawInboxItemCommand($item, 'Settled', null, new ApiKeyActor(Uuid::v7(), 'Weekly CI'))));
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_key_is_refused_a_closed_item_and_it_keeps_its_close(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));

        self::assertSame(['itemId' => 'inbox.item.error.not_open'], $this->refusal(new WithdrawInboxItemCommand($item, 'Settled', null, new ApiKeyActor(Uuid::v7(), 'Weekly CI'))));
        self::assertSame(InboxItemState::Resolved, $this->reload($item)->state);
    }

    public function test_a_key_is_refused_a_blank_reason_and_a_long_one(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $key = new ApiKeyActor(Uuid::v7(), 'Weekly CI');

        self::assertSame(['reason' => WithdrawInboxItemHandler::REASON_BLANK], $this->refusal(new WithdrawInboxItemCommand($item, '   ', null, $key)));
        self::assertSame(['reason' => WithdrawInboxItemHandler::REASON_TOO_LONG], $this->refusal(new WithdrawInboxItemCommand($item, str_repeat('a', 2001), null, $key)));
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    /** @return array<string, string> */
    private function refusal(WithdrawInboxItemCommand $command): array
    {
        try {
            ($this->withdraw)($command);
        } catch (DomainErrors $e) {
            return $e->errors;
        }

        self::fail('Expected a refusal.');
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }
}
