<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Workflow\WaitingAskFactProvider;
use App\Module\Inbox\Workflow\WaitingAskFacts;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class WaitingAskFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    private Card $card;
    private Uuid $bridgeId;
    private int $itemNumber = 100;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->card = $this->stateCard($this->stateProject('inbox-waiting-ask'));
        $this->bridgeId = Uuid::v7();
        $this->setInboxFlag(true);
    }

    public function test_an_open_ask_of_a_session_stopped_on_the_card_waits_even_when_the_question_names_no_card(): void
    {
        $ask = $this->askOfStoppedSession(WorkerRunState::Blocked);

        $facts = $this->build();

        self::assertTrue($facts->waits());
        self::assertSame([(string) $ask->id], $facts->askIds);
        self::assertSame([(string) $ask->id], $this->provider()->fingerprint($facts));
    }

    public function test_a_session_whose_run_did_not_stop_to_wait_gives_no_wait(): void
    {
        $this->askOfStoppedSession(WorkerRunState::Running);
        $this->askOfStoppedSession(WorkerRunState::Succeeded);

        self::assertFalse($this->build()->waits());
    }

    public function test_a_session_that_has_run_again_since_gives_no_wait(): void
    {
        $ask = $this->askOfStoppedSession(WorkerRunState::Blocked);
        $this->runOf($ask->sessionId, WorkerRunState::Running, '2026-10-18 16:00:00');

        self::assertFalse($this->build()->waits());
    }

    public function test_a_closed_ask_or_one_with_no_open_blocking_item_gives_no_wait(): void
    {
        $closed = $this->askOfStoppedSession(WorkerRunState::Blocked);
        $closed->closedAt = new \DateTimeImmutable('2026-10-18 15:00:00');
        $answered = $this->askOfStoppedSession(WorkerRunState::Blocked);
        $link = $answered->items->first() ?: throw new \LogicException('The ask holds an item.');
        $link->item->state = InboxItemState::Resolved;
        $this->askOfStoppedSession(WorkerRunState::Blocked, blocking: false);
        $this->em()->flush();

        self::assertFalse($this->build()->waits());
    }

    public function test_a_session_stopped_on_another_card_gives_no_wait(): void
    {
        $other = $this->stateCard($this->card->project);
        $this->askOfStoppedSession(WorkerRunState::Blocked, $other);

        self::assertFalse($this->build()->waits());
    }

    public function test_an_inbox_that_is_off_gives_no_wait(): void
    {
        $this->askOfStoppedSession(WorkerRunState::Blocked);
        $this->setInboxFlag(false);

        self::assertTrue($this->provider()->isOn());
        self::assertFalse($this->build()->waits());
    }

    private function askOfStoppedSession(WorkerRunState $state, ?Card $card = null, bool $blocking = true): InboxAsk
    {
        $item = new InboxItem($this->card->project, $this->itemNumber++, InboxItemKind::Question, 'Which option?', $blocking, origin: InboxAskOrigin::Agent);
        $ask = new InboxAsk(project: $this->card->project, sessionId: Uuid::v4(), bridgeId: $this->bridgeId);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em()->persist($item);
        $this->em()->persist($ask);
        $this->runOf($ask->sessionId, $state, '2026-10-18 14:00:00', $card ?? $this->card);
        $this->em()->flush();

        return $ask;
    }

    private function runOf(?Uuid $sessionId, WorkerRunState $state, string $receivedAt, ?Card $card = null): void
    {
        $card ??= $this->card;
        $this->em()->persist(new WorkerRun(
            project: $card->project,
            bridgeId: $this->bridgeId,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('The card is flushed.'),
            cardNumber: $card->number,
            workKind: 'fix',
            state: $state,
            sessionId: $sessionId,
            receivedAt: new \DateTimeImmutable($receivedAt),
        ));
        $this->em()->flush();
    }

    private function setInboxFlag(bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = $enabled;
        $this->em()->flush();
    }

    private function build(): WaitingAskFacts
    {
        $facts = $this->provider()->build($this->card->snapshot());
        self::assertInstanceOf(WaitingAskFacts::class, $facts);

        return $facts;
    }

    private function provider(): WaitingAskFactProvider
    {
        $provider = self::getContainer()->get(WaitingAskFactProvider::class);
        self::assertInstanceOf(WaitingAskFactProvider::class, $provider);

        return $provider;
    }
}
