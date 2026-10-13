<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\Workflow\HoldFactProvider;
use App\Module\Bridge\Workflow\HoldFacts;
use App\Module\Bridge\Workflow\RunStoppedFactProvider;
use App\Module\Bridge\Workflow\RunStoppedFacts;
use App\Module\Bridge\Workflow\SessionFactProvider;
use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Bridge\Workflow\WorkerRunFactProvider;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFactProvider;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\FactProvider;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MarkerFactProvidersTest extends KernelTestCase
{
    use CardStateFixtures;

    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->card = $this->stateCard($this->stateProject('bridge-markers'));
    }

    public function test_the_open_worker_runs_give_the_start_of_the_oldest_run_of_each_kind(): void
    {
        $this->openRun($this->card, 'implement', '2026-10-02 09:45:00');
        $this->openRun($this->card, 'implement', '2026-10-02 09:30:00');
        $this->openRun($this->card, 'fix', '2026-10-02 10:00:00');
        $this->workerRun(WorkerRunKind::Interactive, WorkerRunState::Running, '2026-10-02 08:00:00', 'review');

        $facts = $this->build(WorkerRunFactProvider::class, WorkerRunFacts::class);

        self::assertSame(['fix', 'implement'], $facts->activeKinds);
        self::assertEquals(['fix' => new \DateTimeImmutable('2026-10-02 10:00:00'), 'implement' => new \DateTimeImmutable('2026-10-02 09:30:00')], $facts->since);
    }

    public function test_the_live_work_requests_give_the_time_of_the_request_of_each_kind(): void
    {
        $this->requestWork($this->card, 'review', '2026-10-02 09:30:00');
        $this->requestWork($this->card, 'fix', '2026-10-02 09:40:00');

        $facts = $this->build(WorkRequestFactProvider::class, WorkRequestFacts::class);

        self::assertSame(['review', 'fix'], $facts->activeKinds);
        self::assertEquals(['review' => new \DateTimeImmutable('2026-10-02 09:30:00'), 'fix' => new \DateTimeImmutable('2026-10-02 09:40:00')], $facts->since);
    }

    public function test_an_open_interactive_run_is_an_open_session(): void
    {
        $this->openRun($this->card, 'implement', '2026-10-02 08:00:00');
        self::assertNull($this->build(SessionFactProvider::class, SessionFacts::class)->openSince);
        self::assertFalse($this->provider(SessionFactProvider::class)->fingerprint($this->build(SessionFactProvider::class, SessionFacts::class)));

        $this->workerRun(WorkerRunKind::Interactive, WorkerRunState::Running, '2026-10-02 09:00:00', 'review');
        $facts = $this->build(SessionFactProvider::class, SessionFacts::class);

        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $facts->openSince);
        self::assertSame('review', $facts->kind);
        self::assertTrue($this->provider(SessionFactProvider::class)->fingerprint($facts));
    }

    public function test_a_run_that_gave_up_stops_the_card_until_the_card_moves(): void
    {
        self::assertNull($this->build(RunStoppedFactProvider::class, RunStoppedFacts::class)->stopped());

        $run = $this->workerRun(WorkerRunKind::Worker, WorkerRunState::GaveUp, '2026-10-02 09:00:00', 'implement');
        $facts = $this->build(RunStoppedFactProvider::class, RunStoppedFacts::class);

        self::assertSame(WorkerRunState::GaveUp, $facts->stopped()?->state);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $facts->stopped()->endedAt);
        self::assertSame([(string) $run->id, 'gave-up'], $this->provider(RunStoppedFactProvider::class)->fingerprint($facts));

        $this->recordHistory($this->card, '2026-10-02 09:05:00');
        $moved = $this->build(RunStoppedFactProvider::class, RunStoppedFacts::class);

        self::assertNull($moved->stopped());
        self::assertNull($this->provider(RunStoppedFactProvider::class)->fingerprint($moved));
    }

    public function test_a_hold_gives_the_time_the_person_held_the_card(): void
    {
        self::assertNull($this->build(HoldFactProvider::class, HoldFacts::class)->heldAt);

        $this->em()->persist(new CardHold($this->card->project, $this->card->id ?? throw new \LogicException('A flushed card has an id.'), null, new \DateTimeImmutable('2026-10-02 09:00:00')));
        $this->em()->flush();

        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $this->build(HoldFactProvider::class, HoldFacts::class)->heldAt);
    }

    private function workerRun(WorkerRunKind $kind, WorkerRunState $state, string $at, ?string $workKind): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $this->card->number,
            workKind: $workKind,
            state: $state,
            sessionId: Uuid::v4(),
            startedAt: new \DateTimeImmutable($at),
            receivedAt: new \DateTimeImmutable($at),
            kind: $kind,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    /**
     * @template T of object
     *
     * @param class-string<FactProvider> $provider
     * @param class-string<T>            $class
     *
     * @return T
     */
    private function build(string $provider, string $class): object
    {
        $facts = $this->provider($provider)->build($this->card->snapshot());
        self::assertInstanceOf($class, $facts);

        return $facts;
    }

    /** @param class-string<FactProvider> $class */
    private function provider(string $class): FactProvider
    {
        $provider = self::getContainer()->get($class);
        self::assertInstanceOf(FactProvider::class, $provider);

        return $provider;
    }
}
