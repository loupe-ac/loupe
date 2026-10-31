<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SettleWorkRequestCommand;
use App\Module\Bridge\Command\SettleWorkRequestHandler;
use App\Module\Bridge\Command\SettleWorkRequestResult;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\BridgeWorkMatchRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\AccountLimits;
use App\Module\Bridge\Service\AccountReadingRecorder;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\AccountLimitCause;
use App\Module\Bridge\ValueObject\UsageLimitHandBack;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Repository\ProjectRepository;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
use App\Tests\Support\DispatchedEvents;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SettleWorkRequestHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    /** @var list<Update> */
    private array $runsChanged = [];

    public function test_a_settlement_is_announced_once(): void
    {
        [$owner, $request] = $this->claimed('settle-announced');
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $depth = $this->em()->getConnection()->getTransactionNestingLevel();

        self::assertTrue($this->settle($owner, $request, WorkRequestState::Refused)->settled);
        self::assertFalse($this->settle($owner, $request, WorkRequestState::Refused)->settled);

        self::assertCount(1, $changes->events());
        self::assertSame((string) $request->id, (string) $changes->events()[0]->workRequestId);
        self::assertSame(WorkRequestState::Refused, $changes->events()[0]->state);
        self::assertSame([$depth], $changes->transactionDepths());
    }

    /** A withdrawal keeps the bridge and the token, so only the state tells the holder it lost. */
    public function test_a_withdrawn_request_is_a_lost_claim(): void
    {
        [$owner, $request] = $this->claimed('settle-withdrawn');
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable(self::NOW));
        $this->em()->flush();
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->settle($owner, $request, WorkRequestState::Done);
        self::assertSame([], $changes->events());

        self::assertSame(WorkRequestRefusal::ClaimLost, $result->refusal);
        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    /** The row is read fresh under the lock, so a reopen that bypassed the ORM is seen. */
    public function test_a_claim_reopened_behind_the_managed_copy_is_lost(): void
    {
        [$owner, $request] = $this->claimed('settle-fresh');
        $this->em()->getConnection()->executeStatement(
            "UPDATE work_requests SET state = 'open', bridge_id = NULL, claim_token = NULL, lease_until = NULL WHERE id = ?",
            [(string) $request->id],
        );

        $result = $this->settle($owner, $request, WorkRequestState::Done);

        self::assertSame(WorkRequestRefusal::ClaimLost, $result->refusal);
    }

    public function test_a_request_does_not_settle_to_a_state_a_bridge_never_gives(): void
    {
        [$owner, $request] = $this->claimed('settle-wrong');

        $this->expectException(\LogicException::class);

        $this->settle($owner, $request, WorkRequestState::Expired);
    }

    public function test_a_settlement_tells_the_handler_of_its_subject(): void
    {
        $subjects = new RecordingWorkSubjectHandler();
        [$owner, $request] = $this->claimed('settle-subject', new WorkSubject(RecordingWorkSubjectHandler::TYPE, Uuid::v7()));

        self::assertTrue($this->settle($owner, $request, WorkRequestState::Refused, $subjects)->settled);
        self::assertFalse($this->settle($owner, $request, WorkRequestState::Refused, $subjects)->settled);

        self::assertCount(1, $subjects->settled);
        self::assertSame((string) $request->id, (string) $subjects->settled[0]->id);
        self::assertSame(WorkRequestState::Refused, $subjects->settled[0]->state);
        self::assertSame([], $subjects->expired);
    }

    public function test_a_settlement_on_a_card_tells_no_subject_handler(): void
    {
        $subjects = new RecordingWorkSubjectHandler();
        [$owner, $request] = $this->claimed('settle-card');

        self::assertTrue($this->settle($owner, $request, WorkRequestState::Done, $subjects)->settled);

        self::assertSame([], $subjects->settled);
    }

    public function test_a_hand_back_opens_the_request_again_with_the_wait_and_the_session(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back');
        $bridgeId = $request->bridgeId;
        $session = Uuid::v4();
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $subjects = new RecordingWorkSubjectHandler();

        $result = $this->settle($owner, $request, WorkRequestState::Open, $subjects, self::handBack(sessionId: $session));

        self::assertTrue($result->handedBack);
        self::assertFalse($result->settled);
        self::assertNull($result->refusal);
        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id) ?? throw new \LogicException('The request exists.');
        self::assertSame(WorkRequestState::Open, $stored->state);
        self::assertNull($stored->bridgeId);
        self::assertNull($stored->claimToken);
        self::assertNull($stored->leaseUntil);
        self::assertNull($stored->settledAt);
        self::assertNull($stored->waitUntil);
        self::assertSame(self::NOW, $stored->reopenedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame(['usage-limit', 'work', $session->toRfc4122()], [$stored->waitCause, $stored->waitAccount, $stored->resumeSessionId?->toRfc4122()]);
        self::assertSame($bridgeId?->toRfc4122(), $stored->targetBridgeId?->toRfc4122());
        self::assertCount(1, $changes->events());
        self::assertSame(WorkRequestState::Open, $changes->events()[0]->state);
        self::assertSame([], $subjects->settled);
        self::assertSame([($bridgeId ?? throw new \LogicException('A claimed request has a bridge.'))->toRfc4122() => 'work'], $this->matchedAccounts($request));
    }

    public function test_a_run_with_no_assistant_turn_binds_the_request_to_no_bridge(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-no-turn');

        $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack(hasAssistantTurn: false));

        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id) ?? throw new \LogicException('The request exists.');
        self::assertNull($stored->targetBridgeId);
        self::assertNull($stored->resumeSessionId);
        self::assertSame('usage-limit', $stored->waitCause);
    }

    public function test_a_hand_back_keeps_the_target_a_request_already_had_when_the_run_did_no_work(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-target');
        $target = Uuid::v4();
        $request->targetBridgeId = $target;
        $this->em()->flush();

        $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack(hasAssistantTurn: false));

        $this->em()->clear();
        self::assertSame($target->toRfc4122(), $this->em()->find(WorkRequest::class, $request->id)?->targetBridgeId?->toRfc4122());
    }

    public function test_a_hand_back_stores_the_reading_that_showed_the_limit(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-reading');
        $bridge = $this->service(BridgeRepository::class)->findOneByOwnerAndId($owner, $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.')) ?? throw new \LogicException('The bridge exists.');

        $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack());

        $limits = $this->service(AccountLimits::class)->forBridge($bridge);
        self::assertSame(AccountLimitCause::Limit, $limits['work']->cause());
        self::assertSame('2026-10-04T17:00:00+00:00', $limits['work']->releaseAt()?->format(\DateTimeInterface::ATOM));
    }

    public function test_a_hand_back_with_a_new_reading_tells_every_project_the_bridge_follows_to_reload(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-reload', recordRunsChanged: true);
        $other = $this->alsoFollowed($owner, $request, 'settle-hand-back-reload');

        $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack());

        self::assertSame(self::sorted([(string) $request->project->id, $other]), $this->publishedProjects(), 'The Agents page of each followed project draws the new reading.');
    }

    public function test_a_hand_back_with_a_known_reading_reloads_only_the_project_of_the_request(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-known', recordRunsChanged: true);
        $this->alsoFollowed($owner, $request, 'settle-hand-back-known');
        $bridge = $this->service(BridgeRepository::class)->findOneByOwnerAndId($owner, $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.')) ?? throw new \LogicException('The bridge exists.');
        $this->service(AccountReadingRecorder::class)->record($bridge, [self::handBack()->report(new \DateTimeImmutable(self::NOW))]);
        $this->em()->flush();

        $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack());

        self::assertSame([(string) $request->project->id], $this->publishedProjects());
    }

    public function test_a_second_hand_back_from_the_same_claim_loses(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-twice');
        [$bridgeId, $token] = [$request->bridgeId, $request->claimToken];
        $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack());
        $request->bridgeId = $bridgeId;
        $request->claimToken = $token;

        $result = $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack());

        self::assertSame(WorkRequestRefusal::ClaimLost, $result->refusal);
        self::assertSame(1, $this->matchCount($request));
    }

    public function test_a_hand_back_cannot_take_back_a_request_that_was_withdrawn(): void
    {
        [$owner, $request] = $this->claimed('settle-hand-back-withdrawn');
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable(self::NOW));
        $this->em()->flush();

        $result = $this->settle($owner, $request, WorkRequestState::Open, handBack: self::handBack());

        self::assertSame(WorkRequestRefusal::ClaimLost, $result->refusal);
        self::assertSame(0, $this->matchCount($request));
    }

    public function test_the_state_open_needs_a_hand_back(): void
    {
        [$owner, $request] = $this->claimed('settle-open-bare');

        $this->expectException(\LogicException::class);

        $this->settle($owner, $request, WorkRequestState::Open);
    }

    private static function handBack(?Uuid $sessionId = null, bool $hasAssistantTurn = true): UsageLimitHandBack
    {
        return new UsageLimitHandBack('work', 'week', null, new \DateTimeImmutable('2026-10-04 17:00:00', new \DateTimeZone('UTC')), true, 'stream', $sessionId, $hasAssistantTurn);
    }

    /** @return array<string, string> account by bridge id */
    private function matchedAccounts(WorkRequest $request): array
    {
        /** @var list<array{bridge_id: string, account: string}> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT bridge_id, account FROM bridge_work_matches WHERE work_request_id = ?', [(string) $request->id]);

        return array_column($rows, 'account', 'bridge_id');
    }

    private function matchCount(WorkRequest $request): int
    {
        return \count($this->matchedAccounts($request));
    }

    /** @return array{User, WorkRequest} */
    private function claimed(string $name, ?WorkSubject $subject = null, bool $recordRunsChanged = false): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        if ($recordRunsChanged) {
            $this->recordRunsChanged();
        }
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $request = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, subject: $subject);
        $request->bridgeId = $bridge->id;
        $request->claimToken = Uuid::v4();
        $request->leaseUntil = new \DateTimeImmutable('2026-10-01 12:32:00');
        $em->flush();

        return [$owner, $request];
    }

    private function settle(User $owner, WorkRequest $request, WorkRequestState $state, ?RecordingWorkSubjectHandler $subjects = null, ?UsageLimitHandBack $handBack = null): SettleWorkRequestResult
    {
        $handler = new SettleWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            new WorkSubjectHandlers(null === $subjects ? [] : [$subjects]),
            $this->service(BridgeRepository::class),
            $this->service(BridgeWorkMatchRepository::class),
            $this->service(AccountReadingRecorder::class),
            $this->service(ProjectRepository::class),
            $this->service(WorkerRunChangedPublisher::class),
        );

        return $handler(new SettleWorkRequestCommand(
            owner: $owner,
            bridgeId: $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.'),
            workRequestId: $request->id ?? throw new \LogicException('A flushed request has an id.'),
            claimToken: $request->claimToken ?? throw new \LogicException('A claimed request has a token.'),
            state: $state,
            reason: null,
            handBack: $handBack,
        ));
    }

    /** Before anything builds the hub: the container hands the replacement only to what it builds afterwards. */
    private function recordRunsChanged(): void
    {
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                if (str_contains($update->getData(), WorkerRunChangedPublisher::TYPE)) {
                    $this->runsChanged[] = $update;
                }

                return 'id';
            },
        ));

        $flags = $this->service(FeatureFlagRepository::class);
        $flags->findAllIndexed()[LiveUpdates::FLAG]->value = true;
        $this->em()->flush();
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    /** @return list<string> the sorted project ids that the runs-changed updates name */
    private function publishedProjects(): array
    {
        $this->service(LiveUpdatePublisher::class)->publish();
        $ids = [];
        foreach ($this->runsChanged as $update) {
            if (1 !== preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $update->getTopics()[0], $match)) {
                self::fail('A runs-changed topic names its project.');
            }
            $ids[] = $match[0];
        }
        $this->runsChanged = [];

        return self::sorted(array_values(array_unique($ids)));
    }

    /** @return string the id of a second project that the bridge of the request follows */
    private function alsoFollowed(User $owner, WorkRequest $request, string $name): string
    {
        $bridge = $this->service(BridgeRepository::class)->findOneByOwnerAndId($owner, $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.')) ?? throw new \LogicException('The bridge exists.');
        $other = (string) $this->project($this->em(), $owner, 'Other '.$name)->id;
        $bridge->projects = [...$bridge->projects, $other];
        $this->em()->flush();

        return $other;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
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
}
