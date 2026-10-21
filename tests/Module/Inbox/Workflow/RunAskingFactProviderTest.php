<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Workflow\RunAskingFactProvider;
use App\Module\Inbox\Workflow\RunAskingFacts;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class RunAskingFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->card = $this->stateCard($this->stateProject('inbox-run-asking'));
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
        $this->em()->flush();
    }

    public function test_a_card_with_no_run_is_not_asking(): void
    {
        $facts = $this->build();

        self::assertFalse($facts->askOpen);
        self::assertNull($facts->graceEndsAt);
    }

    public function test_a_run_that_waits_on_an_open_ask_is_asking(): void
    {
        $this->runWaitingOn(new InboxAsk(project: $this->card->project, sessionId: null, bridgeId: null), '2026-10-18 14:00:00');

        self::assertTrue($this->build()->askOpen);
    }

    public function test_the_grace_runs_from_the_later_of_the_close_and_the_end_of_the_run(): void
    {
        $ask = new InboxAsk(project: $this->card->project, sessionId: null, bridgeId: null);
        $ask->closedAt = new \DateTimeImmutable('2026-10-18 14:10:00');
        $this->runWaitingOn($ask, '2026-10-18 14:20:00');

        $facts = $this->build();

        self::assertFalse($facts->askOpen);
        self::assertEquals(new \DateTimeImmutable('2026-10-18 14:30:00'), $facts->graceEndsAt);
        self::assertSame([false, '2026-10-18T14:30:00+00:00'], $this->provider()->fingerprint($facts));
    }

    private function runWaitingOn(InboxAsk $ask, string $endedAt): void
    {
        $this->em()->persist($ask);
        $this->em()->flush();
        $run = new WorkerRun(
            project: $this->card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('The card is flushed.'),
            cardNumber: $this->card->number,
            workKind: 'fix',
            state: WorkerRunState::Blocked,
            endedAt: new \DateTimeImmutable($endedAt),
            receivedAt: new \DateTimeImmutable('2026-10-18 13:00:00'),
        );
        $run->waitsOnAskId = $ask->id;
        $this->em()->persist($run);
        $this->em()->flush();
    }

    private function build(): RunAskingFacts
    {
        $facts = $this->provider()->build($this->card->snapshot());
        self::assertInstanceOf(RunAskingFacts::class, $facts);

        return $facts;
    }

    private function provider(): RunAskingFactProvider
    {
        $provider = self::getContainer()->get(RunAskingFactProvider::class);
        self::assertInstanceOf(RunAskingFactProvider::class, $provider);

        return $provider;
    }
}
