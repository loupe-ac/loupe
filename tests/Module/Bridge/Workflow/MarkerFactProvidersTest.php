<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\ValueObject\WorkTakerState;
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
use App\Module\Bridge\Workflow\WorkTakerFactProvider;
use App\Module\Bridge\Workflow\WorkTakerFacts;
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

    public function test_a_work_request_that_a_person_started_counts_as_active_but_not_as_rule_work(): void
    {
        $this->requestWork($this->card, 'fix', '2026-10-02 09:40:00');
        $provider = $this->provider(WorkRequestFactProvider::class);
        $before = $provider->fingerprint($this->build(WorkRequestFactProvider::class, WorkRequestFacts::class));

        $person = $this->personRequest('resume', '2026-10-02 09:30:00');
        $facts = $this->build(WorkRequestFactProvider::class, WorkRequestFacts::class);

        self::assertSame(['resume', 'fix'], $facts->activeKinds);
        self::assertSame(['fix'], $facts->activeRuleKinds);
        self::assertSame(['fix'], array_keys($facts->ruleSince));
        self::assertNotSame($before, $provider->fingerprint($facts));

        $person->state = WorkRequestState::Cancelled;
        $this->em()->flush();
        self::assertSame($before, $provider->fingerprint($this->build(WorkRequestFactProvider::class, WorkRequestFacts::class)));
    }

    public function test_a_worker_run_takes_its_origin_from_its_work_request_then_from_its_own_rule_id(): void
    {
        $person = $this->personRequest('resume', '2026-10-02 09:30:00');
        $this->openRun($this->card, 'fix', '2026-10-02 09:00:00');
        $this->linkedRun($person->id, 'resume', null);
        $this->linkedRun(null, 'product-design', 'inbox:start-session');
        $this->linkedRun($person->id, 'breakdown', 'plan-on-entry');
        $this->linkedRun(Uuid::v7(), 'review', 'review-on-entry');
        $provider = $this->provider(WorkerRunFactProvider::class);

        $facts = $this->build(WorkerRunFactProvider::class, WorkerRunFacts::class);

        self::assertSame(['breakdown', 'fix', 'product-design', 'resume', 'review'], $facts->activeKinds);
        self::assertSame(['fix', 'review'], $facts->activeRuleKinds, 'The request outranks the copy on the run. A run with no id or a lost request reads its own copy, and a run with none is a rule\'s.');
        self::assertSame($provider->fingerprint($facts), $provider->fingerprint($this->build(WorkerRunFactProvider::class, WorkerRunFacts::class)));
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

    public function test_a_card_with_no_live_work_request_has_no_taker_reading(): void
    {
        $facts = $this->build(WorkTakerFactProvider::class, WorkTakerFacts::class);

        self::assertSame([], $facts->readings);
        self::assertSame([], $this->provider(WorkTakerFactProvider::class)->fingerprint($facts));
    }

    public function test_an_open_work_request_gets_the_reading_of_its_takers_and_the_fingerprint_follows_the_state(): void
    {
        $this->requestWork($this->card, 'implement', '2026-10-02 09:30:00');
        $facts = $this->build(WorkTakerFactProvider::class, WorkTakerFacts::class);
        self::assertSame(WorkTakerState::None, $facts->readings['implement']->state);
        self::assertSame([['implement', 'none']], $this->provider(WorkTakerFactProvider::class)->fingerprint($facts));

        $bridge = new Bridge($this->card->project->owner, Uuid::v4(), [(string) $this->card->project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $bridge->workKinds = ['implement'];
        $this->em()->persist($bridge);
        $this->em()->flush();
        $facts = $this->build(WorkTakerFactProvider::class, WorkTakerFacts::class);

        self::assertSame(WorkTakerState::Available, $facts->readings['implement']->state);
        self::assertSame([['implement', 'available']], $this->provider(WorkTakerFactProvider::class)->fingerprint($facts));
    }

    public function test_a_claimed_work_request_has_no_taker_reading(): void
    {
        $this->requestWork($this->card, 'implement')->state = WorkRequestState::Claimed;
        $this->em()->flush();

        self::assertSame([], $this->build(WorkTakerFactProvider::class, WorkTakerFacts::class)->readings);
    }

    private function personRequest(string $kind, string $at): WorkRequest
    {
        $request = new WorkRequest($this->card->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('A flushed card has an id.'), $this->card->number, $kind, null, 'inbox:ask-answer', new \DateTimeImmutable($at));
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function linkedRun(?Uuid $workRequestId, string $workKind, ?string $ruleId): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $this->card->number,
            workKind: $workKind,
            state: WorkerRunState::Running,
            startedAt: new \DateTimeImmutable('2026-10-02 09:10:00'),
            receivedAt: new \DateTimeImmutable('2026-10-02 09:10:00'),
            workRequestId: $workRequestId,
            ruleId: $ruleId,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
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
