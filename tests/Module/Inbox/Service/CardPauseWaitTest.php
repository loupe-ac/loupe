<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Mcp\InboxItemPayload;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Messenger\ReconcileCardWaitsHandler;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** A pause or card entry in the inbox set opens a wait, and its removal ends it, through the stored set and the queued reconcile. */
final class CardPauseWaitTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'pause-waits'), 'pause-waits');
        $this->card = $this->card($em, $this->project, 7);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->transport()->reset();
    }

    public function test_a_change_of_the_stored_set_queues_the_reconcile_that_opens_and_ends_the_wait(): void
    {
        $pauseId = Uuid::v7();
        $this->store($pauseId);
        $this->drain();

        $wait = $this->onlyWait($this->onlyWatch());
        self::assertSame('paused', $wait->markerKey);
        self::assertSame('marker.paused', $wait->label);
        self::assertEquals($pauseId, $wait->pauseId);
        self::assertSame(InboxItemState::Open, $this->onlyWatch()->item->state);

        $this->store(null);
        $this->drain();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Resolved, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_a_new_pause_after_the_end_of_the_first_opens_a_new_wait(): void
    {
        $this->store(Uuid::v7());
        $this->drain();
        $this->store(null);
        $this->drain();

        $second = Uuid::v7();
        $this->store($second);
        $this->drain();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertEquals($second, $this->onlyWait($watches[1])->pauseId);
    }

    public function test_the_project_sweep_finds_a_card_that_only_has_a_stored_entry(): void
    {
        $this->store(Uuid::v7());
        $this->transport()->reset();
        self::assertSame([], $this->watches());

        $reconciler = self::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $reconciler->reconcile($this->project, null);

        self::assertSame('paused', $this->onlyWait($this->onlyWatch())->markerKey);
    }

    public function test_inbox_get_reads_the_pause_reason_and_the_failed_run_link_in_the_body(): void
    {
        $run = new WorkerRun($this->project, Uuid::v7(), WorkSubject::CARD, $this->cardId(), 7, 'repair', WorkerRunState::Failed, failureReason: 'the before command exited with code 141', receivedAt: new \DateTimeImmutable('2026-10-26 18:57:17'), ruleId: 'implement');
        $pause = new CardPause($this->card, $this->project, 'repair-failed', 'implement', PauseKind::Retries, new \DateTimeImmutable('2026-10-26 18:57:20'));
        $this->em->persist($run);
        $this->em->persist($pause);
        $this->em->flush();
        $this->store($pause->id ?? throw new \LogicException('The pause is stored.'));
        $this->drain();

        $payload = self::getContainer()->get(InboxItemPayload::class);
        self::assertInstanceOf(InboxItemPayload::class, $payload);
        $item = $payload->forItem($this->onlyWatch()->item, []);

        self::assertSame('Paused: The repair worker could not fix the failure', $item['title']);
        self::assertStringContainsString('The last repair worker run failed: the before command exited with code 141.', $item['body'] ?? '');
        self::assertMatchesRegularExpression('~\[Open the failed run\]\(https?://[^)]+search='.preg_quote($run->id?->toRfc4122() ?? '', '~').'\)~', $item['body'] ?? '');
    }

    public function test_a_card_entry_opens_a_wait_for_one_spell_and_its_removal_ends_it(): void
    {
        $spell = new \DateTimeImmutable('2026-10-20 09:00:00');
        $card = $this->em->find(Card::class, $this->cardId()) ?? throw new \LogicException('The card is stored.');
        $this->storeInbox($this->em, $card, $this->inboxEntry('nothing-happened', MarkerSubject::card($this->cardId(), $spell)));
        $this->drain();

        $watch = $this->onlyWatch();
        $wait = $this->onlyWait($watch);
        self::assertSame('nothing-happened', $wait->markerKey);
        self::assertEquals($spell, $wait->spellStartedAt);
        self::assertSame(InboxItemState::Open, $watch->item->state);

        $card = $this->em->find(Card::class, $this->cardId()) ?? throw new \LogicException('The card is stored.');
        $this->storeInbox($this->em, $card);
        $this->drain();

        self::assertSame(InboxItemState::Resolved, $this->onlyWatch()->item->state);
    }

    /** Stores the inbox set as the engine does, with one pause entry or none. */
    private function store(?Uuid $pauseId): void
    {
        $card = $this->em->find(Card::class, $this->cardId()) ?? throw new \LogicException('The card is stored.');
        $entries = null === $pauseId ? [] : [$this->inboxEntry('paused', new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => $pauseId->toRfc4122()]))];
        $this->storeInbox($this->em, $card, ...$entries);
    }

    /** Runs each queued reconcile, and fails when none was queued. */
    private function drain(): void
    {
        $handler = self::getContainer()->get(ReconcileCardWaitsHandler::class);
        self::assertInstanceOf(ReconcileCardWaitsHandler::class, $handler);
        $messages = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof ReconcileCardWaits,
        ));
        self::assertNotEmpty($messages);
        $this->transport()->reset();
        foreach ($messages as $message) {
            $handler($message);
        }
        $this->em->clear();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<InboxCardWatch> in item number order */
    private function watches(): array
    {
        $repository = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $repository);
        $watches = $repository->findBy(['cardId' => $this->card->id]);
        usort($watches, static fn (InboxCardWatch $a, InboxCardWatch $b): int => $a->item->number <=> $b->item->number);

        return $watches;
    }

    private function onlyWatch(): InboxCardWatch
    {
        $watches = $this->watches();
        self::assertCount(1, $watches);

        return $watches[0];
    }

    private function onlyWait(InboxCardWatch $watch): InboxCardWait
    {
        self::assertCount(1, $watch->waits);
        $wait = $watch->waits->first();
        self::assertInstanceOf(InboxCardWait::class, $wait);

        return $wait;
    }

    private function cardId(): Uuid
    {
        return $this->card->id ?? throw new \LogicException('Card has no id.');
    }
}
