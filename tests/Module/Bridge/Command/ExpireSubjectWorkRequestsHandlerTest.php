<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ExpireSubjectWorkRequestsCommand;
use App\Module\Bridge\Command\ExpireSubjectWorkRequestsHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Workflow\Contract\PersonWork;
use App\Module\Workflow\Contract\WorkHolds;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class ExpireSubjectWorkRequestsHandlerTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    private const string NOW = '2026-10-01 12:00:00';

    public function test_the_open_requests_of_other_subjects_past_the_timeout_expire(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-subjects@example.com'), 'Expire Subjects');
        $lapsed = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'), subject: $this->analysis());
        $recent = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:01'), subject: $this->analysis());
        $claimed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), subject: $this->analysis());
        $card = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'));
        $subjects = new RecordingWorkSubjectHandler();

        self::assertSame(1, $this->handler($subjects)(new ExpireSubjectWorkRequestsCommand()));

        $em->clear();
        self::assertSame(WorkRequestState::Expired, $em->find(WorkRequest::class, $lapsed->id)?->state);
        foreach ([[$recent, WorkRequestState::Open], [$claimed, WorkRequestState::Claimed], [$card, WorkRequestState::Open]] as [$request, $state]) {
            self::assertSame($state, $em->find(WorkRequest::class, $request->id)?->state);
        }
        self::assertSame([(string) $lapsed->id], array_map(static fn (WorkRequest $request): string => (string) $request->id, $subjects->expired));
        self::assertSame([(string) $lapsed->id], array_column($this->outboxPayloads(), 'workRequestId'));
    }

    public function test_an_open_request_of_a_person_expires_after_the_template_timeout(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-person@example.com'), 'Expire Person');
        $ruleId = PersonWork::RULE_PREFIX.'send-agent';
        $lapsed = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'), ruleId: $ruleId);
        $restarted = $this->seedWorkRequest($em, $project, kind: 'tech-design', createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), ruleId: $ruleId, reopenedAt: new \DateTimeImmutable('2026-10-01 10:00:01'));
        $recent = $this->seedWorkRequest($em, $project, kind: 'fix', createdAt: new \DateTimeImmutable('2026-10-01 10:00:01'), ruleId: $ruleId);
        $claimed = $this->seedWorkRequest($em, $project, kind: 'review', state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), ruleId: $ruleId);
        $workflow = $this->seedWorkRequest($em, $project, kind: 'merge', createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'));
        $subjects = new RecordingWorkSubjectHandler();

        self::assertSame(1, $this->handler($subjects)(new ExpireSubjectWorkRequestsCommand()));

        $em->clear();
        self::assertSame(WorkRequestState::Expired, $em->find(WorkRequest::class, $lapsed->id)?->state);
        foreach ([[$restarted, WorkRequestState::Open], [$recent, WorkRequestState::Open], [$claimed, WorkRequestState::Claimed], [$workflow, WorkRequestState::Open]] as [$request, $state]) {
            self::assertSame($state, $em->find(WorkRequest::class, $request->id)?->state);
        }
    }

    public function test_an_open_session_request_of_a_person_expires_after_two_minutes(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-session@example.com'), 'Expire Session');
        $lapsed = $this->seedWorkRequest($em, $project, kind: 'session', capability: 'session', createdAt: new \DateTimeImmutable('2026-10-01 11:57:59'), ruleId: 'inbox:start-session');
        $waiting = $this->seedWorkRequest($em, $project, cardId: Uuid::v7(), kind: 'session', capability: 'session', createdAt: new \DateTimeImmutable('2026-10-01 11:58:01'), ruleId: 'inbox:start-session');
        $claimed = $this->seedWorkRequest($em, $project, cardId: Uuid::v7(), kind: 'session', capability: 'session', state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 11:00:00'), ruleId: 'inbox:start-session');
        $otherRule = $this->seedWorkRequest($em, $project, cardId: Uuid::v7(), kind: 'session', capability: 'session', createdAt: new \DateTimeImmutable('2026-10-01 11:00:00'), ruleId: 'product-design-session');
        $otherKind = $this->seedWorkRequest($em, $project, cardId: Uuid::v7(), kind: 'send-agent', createdAt: new \DateTimeImmutable('2026-10-01 11:00:00'), ruleId: 'inbox:send-agent');

        self::assertSame(1, $this->handler(new RecordingWorkSubjectHandler())(new ExpireSubjectWorkRequestsCommand()));

        $em->clear();
        foreach ([[$lapsed, WorkRequestState::Expired], [$waiting, WorkRequestState::Open], [$claimed, WorkRequestState::Claimed], [$otherRule, WorkRequestState::Open], [$otherKind, WorkRequestState::Open]] as [$request, $state]) {
            self::assertSame($state, $em->find(WorkRequest::class, $request->id)?->state);
        }
        self::assertSame([(string) $lapsed->id], array_column($this->outboxPayloads(), 'workRequestId'));
    }

    public function test_a_tick_with_nothing_to_expire_answers_zero(): void
    {
        self::bootKernel();

        self::assertSame(0, $this->handler(new RecordingWorkSubjectHandler())(new ExpireSubjectWorkRequestsCommand()));
    }

    /** A bridge can claim the request between the read and the expiry. Its claim stands. */
    public function test_an_expiry_only_if_open_leaves_a_claimed_request(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-claimed@example.com'), 'Expire Claimed');
        $claimed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, subject: $this->analysis());
        $subjects = new RecordingWorkSubjectHandler();

        self::assertFalse($this->withdraw($subjects)(new WithdrawWorkRequestCommand($claimed->id ?? throw new \LogicException(), WorkRequestState::Expired, onlyIfOpen: true)));

        $em->clear();
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $claimed->id)?->state);
        self::assertSame([], $subjects->expired);
    }

    /** The end of the hold restarts the timeout of each request it marked, so the sweep must not expire one first. */
    public function test_a_sweep_during_a_work_hold_expires_no_request_of_the_project(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-held@example.com'), 'Expire Held');
        $this->repositoryWithGaps($project);
        $old = new \DateTimeImmutable('2026-10-01 08:00:00');
        $marked = $this->seedWorkRequest($em, $project, createdAt: $old, subject: $this->analysis());
        $marked->workHoldSince = $old;
        $session = $this->seedWorkRequest($em, $project, kind: 'session', capability: 'session', createdAt: $old, ruleId: 'inbox:start-session');
        $session->workHoldSince = $old;
        $person = $this->seedWorkRequest($em, $project, createdAt: $old, ruleId: PersonWork::RULE_PREFIX.'send-agent');
        $person->workHoldSince = $old;
        $em->flush();
        // The hold committed, and its listener has not marked this one yet.
        $unmarked = $this->seedWorkRequest($em, $project, createdAt: $old, subject: $this->analysis());
        $subjects = new RecordingWorkSubjectHandler();

        self::assertSame(0, $this->handler($subjects)(new ExpireSubjectWorkRequestsCommand()));

        $em->clear();
        foreach ([$marked, $session, $person, $unmarked] as $request) {
            self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $request->id)?->state);
        }
        self::assertSame([], $subjects->expired);
    }

    /** The sweep reads a request, then the end of a hold restarts its timeout before the expiry locks it. */
    public function test_an_expiry_by_deadline_leaves_a_request_whose_timeout_restarted(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-restarted@example.com'), 'Expire Restarted');
        $restarted = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), subject: $this->analysis(), reopenedAt: new \DateTimeImmutable(self::NOW));
        $subjects = new RecordingWorkSubjectHandler();

        self::assertFalse($this->withdraw($subjects)(new WithdrawWorkRequestCommand($restarted->id ?? throw new \LogicException(), WorkRequestState::Expired, onlyIfOpen: true, deadline: new \DateTimeImmutable('2026-10-01 10:00:00'))));

        $em->clear();
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $restarted->id)?->state);
        self::assertSame([], $subjects->expired);
    }

    private function analysis(): WorkSubject
    {
        return new WorkSubject(RecordingWorkSubjectHandler::TYPE, Uuid::v7());
    }

    private function handler(RecordingWorkSubjectHandler $subjects): ExpireSubjectWorkRequestsHandler
    {
        return new ExpireSubjectWorkRequestsHandler(
            $this->service(WorkRequestRepository::class),
            $this->withdraw($subjects),
            new MockClock(self::NOW),
            120,
        );
    }

    private function withdraw(RecordingWorkSubjectHandler $subjects): WithdrawWorkRequestHandler
    {
        return new WithdrawWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            new WorkSubjectHandlers([$subjects]),
            $this->service(WorkHolds::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
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
