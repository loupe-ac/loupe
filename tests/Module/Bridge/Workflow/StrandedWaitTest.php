<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\ResumeStrandedWaitsCommand;
use App\Module\Bridge\Command\ResumeStrandedWaitsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Bridge\Workflow\WorkerWaitFactProvider;
use App\Module\Bridge\Workflow\WorkerWaitFacts;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** A wait that ended while its bridge was offline: the fact, the quiet bridge and the resend of the resume. */
final class StrandedWaitTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_quiet_bridge_makes_the_ended_wait_stranded_offline(): void
    {
        [$wait] = $this->strandedWait('stranded-quiet', new \DateTimeImmutable('-1 day'));

        $facts = $this->factsOf($wait);

        self::assertTrue($facts->strandedOffline());
        self::assertNull($facts->changesAt(new \DateTimeImmutable()));
    }

    public function test_a_live_bridge_leaves_the_wait_stranded_but_not_offline_and_the_facts_change_when_it_goes_quiet(): void
    {
        [$wait] = $this->strandedWait('stranded-live', new \DateTimeImmutable());

        $facts = $this->factsOf($wait);

        self::assertFalse($facts->strandedOffline());
        self::assertNotNull($facts->stranded);
        self::assertGreaterThan(new \DateTimeImmutable(), $facts->changesAt(new \DateTimeImmutable()));
    }

    public function test_the_fingerprint_changes_when_the_bridge_goes_quiet(): void
    {
        [$wait, $bridge] = $this->strandedWait('stranded-fingerprint', new \DateTimeImmutable());
        $provider = self::getContainer()->get(WorkerWaitFactProvider::class);
        $live = $provider->fingerprint($this->factsOf($wait));

        $this->em()->getConnection()->executeStatement('UPDATE bridges SET last_seen_at = now() - interval \'1 day\' WHERE id = :id', ['id' => (string) $bridge->id]);

        self::assertNotSame($live, $provider->fingerprint($this->factsOf($wait)));
    }

    public function test_a_wait_that_was_resumed_is_not_stranded(): void
    {
        [$wait] = $this->strandedWait('stranded-resumed', new \DateTimeImmutable('-1 day'));
        $wait->resumeRunId = Uuid::v7();
        $this->em()->flush();

        self::assertNull($this->factsOf($wait)->stranded);
    }

    public function test_the_task_resends_the_resume_for_a_wait_whose_bridge_is_back_and_only_once(): void
    {
        [$wait, $bridge] = $this->strandedWait('stranded-resend', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        $first = $this->resend();
        $second = $this->resend();

        self::assertSame(1, $first);
        self::assertSame(0, $second);
        $this->em()->clear();
        self::assertCount(1, self::getContainer()->get(BridgeCommandRepository::class)->findAll());
        self::assertSame(WorkerWaitEndReason::Condition, $this->em()->find(WorkerWait::class, $wait->id)?->endReason);
    }

    public function test_the_task_leaves_a_wait_whose_bridge_is_still_offline(): void
    {
        [, $bridge] = $this->strandedWait('stranded-offline', new \DateTimeImmutable('-1 day'));
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        self::assertSame(0, $this->resend());
        $this->em()->clear();
        self::assertSame([], self::getContainer()->get(BridgeCommandRepository::class)->findAll());
    }

    public function test_a_stop_of_the_stranded_wait_leaves_no_resume_for_the_stopped_run(): void
    {
        [$wait, $bridge] = $this->strandedWait('stranded-stop', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();
        $run = $this->em()->find(WorkerRun::class, $wait->runId);
        self::assertInstanceOf(WorkerRun::class, $run);

        self::assertTrue(self::getContainer()->get(WorkerWaitEnder::class)->stopStranded($wait, WorkerWaitEndReason::CancelledByPerson));
        // The caller still holds the run as it read it before the stop.
        $run->state = WorkerRunState::Waiting;
        $refused = null;
        try {
            self::getContainer()->get(RequestBridgeCommandHandler::class)(new RequestBridgeCommandCommand($run, BridgeCommandKind::ResumeRun, null, cause: BridgeCommandCause::WaitEnded));
        } catch (DomainErrors $e) {
            $refused = $e;
        }

        self::assertInstanceOf(DomainErrors::class, $refused);
        $this->em()->clear();
        self::assertSame([], self::getContainer()->get(BridgeCommandRepository::class)->findAll());
    }

    private function resend(): int
    {
        return (self::getContainer()->get(ResumeStrandedWaitsHandler::class))(new ResumeStrandedWaitsCommand());
    }

    private function factsOf(WorkerWait $wait): WorkerWaitFacts
    {
        $this->em()->clear();
        $facts = self::getContainer()->get(WorkerWaitFactProvider::class)->build($this->snapshot($wait));
        self::assertInstanceOf(WorkerWaitFacts::class, $facts);

        return $facts;
    }

    private function snapshot(WorkerWait $wait): CardSnapshot
    {
        return new CardSnapshot($wait->cardId, $wait->project->id ?? throw new \LogicException('Stored.'), 1, 'feature', new ColumnRef(Uuid::v7(), false, false), null, null, null);
    }

    /** @return array{WorkerWait, Bridge} */
    private function strandedWait(string $name, \DateTimeImmutable $bridgeSeenAt): array
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8));
        $cardId = $this->seedCard($em, $project)->id ?? throw new \LogicException('Seeded.');
        $bridge = $this->seedBridge($em, $owner, lastSeenAt: $bridgeSeenAt);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Waiting);
        $opened = new \DateTimeImmutable('-30 minutes');
        $wait = new WorkerWait($project, $cardId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, $bridge->id, $run->sessionId ?? throw new \LogicException('Seeded.'), [], $opened, $opened->modify('+60 minutes'));
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('-10 minutes'));
        $em->persist($wait);
        $em->flush();

        return [$wait, $bridge];
    }
}
