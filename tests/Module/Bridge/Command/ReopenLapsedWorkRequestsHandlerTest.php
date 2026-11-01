<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ReopenLapsedWorkRequestsCommand;
use App\Module\Bridge\Command\ReopenLapsedWorkRequestsHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\HeldProjects;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReopenLapsedWorkRequestsHandlerTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    private const string NOW = '2026-10-01 12:30:00';

    public function test_a_lapsed_claim_opens_again_and_tells_the_bridges(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-lapsed@example.com'), 'Reopen Lapsed');
        $lapsed = $this->claimed($project, '2026-10-01 12:29:59');
        $atTheEdge = $this->claimed($project, self::NOW);
        $live = $this->claimed($project, '2026-10-01 12:30:01');
        $this->seedWorkRequest($em, $project);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $depth = $em->getConnection()->getTransactionNestingLevel();

        self::assertSame(2, $this->handler()(new ReopenLapsedWorkRequestsCommand()));

        self::assertEqualsCanonicalizing([(string) $lapsed->id, (string) $atTheEdge->id], array_map(static fn (WorkRequestChanged $event): string => (string) $event->workRequestId, $changes->events()));
        self::assertSame([WorkRequestState::Open, WorkRequestState::Open], array_map(static fn (WorkRequestChanged $event): WorkRequestState => $event->state, $changes->events()));
        self::assertSame([$depth, $depth], $changes->transactionDepths());

        $em->clear();
        foreach ([$lapsed, $atTheEdge] as $request) {
            $stored = $em->find(WorkRequest::class, $request->id);
            self::assertSame(WorkRequestState::Open, $stored?->state);
            self::assertNull($stored->bridgeId);
            self::assertNull($stored->claimToken);
            self::assertNull($stored->leaseUntil);
        }
        $kept = $em->find(WorkRequest::class, $live->id);
        self::assertSame(WorkRequestState::Claimed, $kept?->state);
        self::assertSame('2026-10-01 12:30:01', $kept->leaseUntil?->format('Y-m-d H:i:s'));

        $payloads = $this->outboxPayloads();
        self::assertEqualsCanonicalizing([(string) $lapsed->id, (string) $atTheEdge->id], array_column($payloads, 'workRequestId'));
        self::assertSame(['open', 'open'], array_column($payloads, 'state'));
        self::assertSame([(string) $project->id, (string) $project->id], array_column($payloads, 'projectId'));
    }

    public function test_a_reopened_request_keeps_its_context(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-context@example.com'), 'Reopen Context');
        $lapsed = $this->claimed($project, '2026-10-01 12:00:00');
        $context = new WorkRequestContext(42, 'https://github.com/acme/widgets/pull/42', 'abc1234', 'conflict');
        $lapsed->context = $context;
        $em->flush();

        self::assertSame(1, $this->handler()(new ReopenLapsedWorkRequestsCommand()));

        $em->clear();
        self::assertEquals($context, $em->find(WorkRequest::class, $lapsed->id)?->context);
        self::assertSame($context->toArray(), $this->outboxPayloads()[0]['context']);
    }

    public function test_a_sweep_with_no_lapsed_claim_writes_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-quiet@example.com'), 'Reopen Quiet');
        $this->claimed($project, '2026-10-01 12:31:00');
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        self::assertSame(0, $this->handler()(new ReopenLapsedWorkRequestsCommand()));
        self::assertSame([], $changes->events());

        self::assertSame([], $this->outboxPayloads());
    }

    /**
     * A hold locks the project row for update. The sweep takes a share lock on
     * that row before it writes, so a hold that commits first keeps the claim.
     */
    public function test_a_sweep_locks_the_project_of_a_card_request_before_it_writes(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-lock@example.com'), 'Reopen Lock');
        $this->claimed($project, '2026-10-01 12:29:59');
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        self::assertSame(1, $this->handler()(new ReopenLapsedWorkRequestsCommand()));

        $statements = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }
        $lock = array_find_key($statements, static fn (string $sql): bool => str_contains($sql, 'FROM projects') && str_contains($sql, 'FOR SHARE'));
        $write = array_find_key($statements, static fn (string $sql): bool => str_contains($sql, 'UPDATE work_requests'));
        self::assertNotNull($write);
        self::assertNotNull($lock, 'The sweep took no share lock on the project.');
        self::assertLessThan($write, $lock);
    }

    public function test_a_lapsed_claim_of_another_subject_opens_again_with_no_card_request(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-subject@example.com'), 'Reopen Subject');
        $request = $this->seedWorkRequest(
            $em,
            $project,
            state: WorkRequestState::Claimed,
            bridgeId: Uuid::v4(),
            claimToken: Uuid::v4(),
            leaseUntil: new \DateTimeImmutable('2026-10-01 12:29:59'),
            subject: new WorkSubject('analysis', Uuid::v7()),
        );

        self::assertSame(1, $this->handler()(new ReopenLapsedWorkRequestsCommand()));

        $em->clear();
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_a_lapsed_claim_of_a_project_that_holds_its_new_work_stays_claimed_until_the_hold_ends(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-held@example.com'), 'Reopen Held');
        $repository = $this->repositoryWithGaps($project);
        $card = $this->claimed($project, '2026-10-01 12:29:59');
        $analysis = $this->seedWorkRequest(
            $em,
            $project,
            state: WorkRequestState::Claimed,
            bridgeId: Uuid::v4(),
            claimToken: Uuid::v4(),
            leaseUntil: new \DateTimeImmutable('2026-10-01 12:29:59'),
            subject: new WorkSubject('analysis', Uuid::v7()),
        );

        self::assertSame(0, $this->handler()(new ReopenLapsedWorkRequestsCommand()));
        $em->clear();
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $card->id)?->state);
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $analysis->id)?->state);

        $em->getConnection()->executeStatement('DELETE FROM forge_repositories WHERE id = :id', ['id' => $repository->id?->toRfc4122()]);

        self::assertSame(2, $this->handler()(new ReopenLapsedWorkRequestsCommand()));
        $em->clear();
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $card->id)?->state);
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $analysis->id)?->state);
    }

    private function claimed(Project $project, string $leaseUntil): WorkRequest
    {
        return $this->seedWorkRequest(
            $this->em(),
            $project,
            state: WorkRequestState::Claimed,
            bridgeId: Uuid::v4(),
            claimToken: Uuid::v4(),
            leaseUntil: new \DateTimeImmutable($leaseUntil),
        );
    }

    private function handler(): ReopenLapsedWorkRequestsHandler
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $outbox = self::getContainer()->get(OutboxWriter::class);
        self::assertInstanceOf(OutboxWriter::class, $outbox);

        $announcer = self::getContainer()->get(WorkRequestAnnouncer::class);
        self::assertInstanceOf(WorkRequestAnnouncer::class, $announcer);

        return new ReopenLapsedWorkRequestsHandler(new WorkRequestRepository($registry), $outbox, $this->em(), new MockClock(self::NOW), $announcer, self::getContainer()->get(HeldProjects::class), self::getContainer()->get(BridgeLiveness::class));
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE type = 'bridge.work_request' ORDER BY sequence",
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
