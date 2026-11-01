<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Command\ClaimWorkRequestResult;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\BridgeClaimRefusalRepository;
use App\Module\Bridge\Repository\BridgeWorkMatchRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ClaimWorkRequestHandlerTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    public function test_a_claim_counts_and_leases_from_the_clock(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-ok', WorkRequestState::Open);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->refusal);
        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
        self::assertSame(1, $result->request->claims);
        self::assertSame('2026-10-01T13:00:00+00:00', $result->request->leaseUntil?->format(\DateTimeInterface::ATOM));
        self::assertNotNull($result->request->claimToken);

        self::assertCount(1, $changes->events());
        self::assertSame((string) $request->id, (string) $changes->events()[0]->workRequestId);
        self::assertSame((string) $request->project->id, (string) $changes->events()[0]->projectId);
        self::assertSame(WorkRequestState::Claimed, $changes->events()[0]->state);
    }

    /**
     * The test transaction holds the heartbeat lock of the bridge after the
     * claim, so a heartbeat cannot change the bridge between the checks and the claim.
     */
    public function test_a_claim_takes_the_heartbeat_lock_of_the_bridge(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-lock', WorkRequestState::Open);
        $this->claim($owner, $bridge, $request);
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());

        try {
            self::assertFalse((bool) $other->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtext(?))',
                ['bridge:'.$owner->id?->toRfc4122().':'.$bridge->id->toRfc4122()],
            ));
        } finally {
            $other->close();
        }
    }

    public function test_a_bridge_with_app_prompts_claims_a_subject_request_that_carries_a_prompt(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-app-prompts', WorkRequestState::Open);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
        $request->capability = 'subject-analysis';
        $request->prompt = 'Run it.';
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->refusal);
        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
    }

    public function test_a_bridge_with_app_prompts_does_not_claim_a_subject_request_with_no_prompt(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-no-prompt', WorkRequestState::Open);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
        $request->capability = 'subject-analysis';
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request);

        self::assertSame(WorkRequestRefusal::CapabilityMissing, $result->refusal);
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function settledStates(): iterable
    {
        yield 'done' => [WorkRequestState::Done];
        yield 'refused' => [WorkRequestState::Refused];
        yield 'cancelled' => [WorkRequestState::Cancelled];
        yield 'expired' => [WorkRequestState::Expired];
    }

    #[DataProvider('settledStates')]
    public function test_a_request_that_is_no_longer_open_is_not_claimed(WorkRequestState $state): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-settled', $state);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->claim($owner, $bridge, $request);
        self::assertSame([], $changes->events());

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::AlreadyClaimed, $result->refusal);
        $this->em()->clear();
        self::assertSame($state, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_a_request_of_a_held_card_is_refused_and_stays_open(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-held', WorkRequestState::Open);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $this->hold($request, $owner);

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::CardHeld, $result->refusal);
        self::assertSame([], $changes->events());
        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_a_request_of_a_card_whose_hold_ended_is_claimed(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-released', WorkRequestState::Open);
        $this->hold($request, $owner);
        self::getContainer()->get(CardHolds::class)->release($request->project, [$request->subjectId]);

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->refusal);
        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
    }

    public function test_a_card_request_of_a_project_that_holds_its_new_work_is_refused_and_stays_open(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-project-held', WorkRequestState::Open);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $this->repositoryWithGaps($request->project);

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::ProjectHeld, $result->refusal);
        self::assertSame('project_held', $result->refusal->code());
        self::assertSame(409, $result->refusal->httpStatus());
        self::assertSame([], $changes->events());
        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_a_request_about_another_subject_of_a_project_that_holds_its_new_work_is_refused_too(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-project-held-analysis', WorkRequestState::Open);
        $analysis = $this->seedWorkRequest($this->em(), $request->project, subject: new WorkSubject('analysis', Uuid::v7()));
        $this->repositoryWithGaps($request->project);

        $result = $this->claim($owner, $bridge, $analysis);

        self::assertSame(WorkRequestRefusal::ProjectHeld, $result->refusal);
        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $analysis->id)?->state);
    }

    public function test_a_claim_that_commits_before_the_gap_is_stored_goes_on_and_a_later_one_is_refused(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-project-sequence', WorkRequestState::Open);
        $second = $this->seedWorkRequest($this->em(), $request->project, kind: 'fix');

        $first = $this->claim($owner, $bridge, $request);
        $this->repositoryWithGaps($request->project);
        $later = $this->claim($owner, $bridge, $second);

        self::assertNull($first->refusal);
        self::assertSame(WorkRequestState::Claimed, $first->request?->state);
        self::assertSame(WorkRequestRefusal::ProjectHeld, $later->refusal);
    }

    public function test_a_claim_takes_the_key_share_lock_of_the_project_before_it_reads_the_stored_checks(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-project-lock', WorkRequestState::Open);
        $this->repositoryWithGaps($request->project, []);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $this->claim($owner, $bridge, $request);

        $statements = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = trim((string) $query['sql']);
            }
        }
        $lock = array_find_key($statements, static fn (string $sql): bool => str_contains($sql, 'FROM projects') && str_ends_with($sql, 'FOR KEY SHARE'));
        $read = array_find_key($statements, static fn (string $sql): bool => str_contains($sql, 'forge_repository_checks') && str_starts_with($sql, 'SELECT'));
        self::assertNotNull($lock);
        self::assertNotNull($read);
        self::assertLessThan($read, $lock);
    }

    public function test_a_request_of_another_card_is_claimed_while_a_card_is_held(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-other-card', WorkRequestState::Open);
        $this->em()->persist(new CardHold($request->project, Uuid::v7(), $owner, new \DateTimeImmutable(self::NOW)));
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->refusal);
    }

    public function test_a_request_for_another_bridge_is_not_found_and_stays_open(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-target-other', WorkRequestState::Open);
        $request->targetBridgeId = Uuid::v4();
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::NotFound, $result->refusal);
        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_the_bridge_a_request_names_claims_it(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-target-self', WorkRequestState::Open);
        $request->targetBridgeId = $bridge->id;
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->refusal);
        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
    }

    /** @return iterable<string, array{?string}> */
    public static function accountsTheBridgeDoesNotRunForTheKind(): iterable
    {
        yield 'no account' => [null];
        yield 'an account the bridge does not list' => ['other'];
        yield 'an account that runs other kinds' => ['spare'];
    }

    #[DataProvider('accountsTheBridgeDoesNotRunForTheKind')]
    public function test_a_claim_with_no_account_the_bridge_runs_for_the_kind_is_refused_and_stays_open(?string $account): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-account-unknown', WorkRequestState::Open);
        $bridge->accounts[] = ['name' => 'spare', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['review']];
        $this->em()->flush();
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->claim($owner, $bridge, $request, $account);

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::AccountUnknown, $result->refusal);
        self::assertSame('account_unknown', $result->refusal->code());
        self::assertSame(422, $result->refusal->httpStatus());
        self::assertSame([], $changes->events());
        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_a_claim_with_no_account_is_accepted_when_every_account_reports_its_kinds_and_none_runs_the_kind(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-no-account-runs', WorkRequestState::Open);
        $bridge->accounts = [
            ['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['review']],
            ['name' => 'spare', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => []],
        ];
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request, null);

        self::assertNull($result->refusal);
        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
    }

    public function test_a_claim_with_no_account_is_refused_when_an_account_row_has_no_kinds_key(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-old-bridge', WorkRequestState::Open);
        $bridge->accounts = [
            ['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['review']],
            ['name' => 'old', 'harness' => 'claude', 'state' => 'ready', 'reason' => null],
        ];
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request, null);

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::AccountUnknown, $result->refusal);
        self::assertSame('account_unknown', $result->refusal->code());
    }

    public function test_a_bridge_that_lists_no_account_claims_nothing(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-no-accounts', WorkRequestState::Open);
        $bridge->accounts = null;
        $this->em()->flush();

        self::assertSame(WorkRequestRefusal::AccountUnknown, $this->claim($owner, $bridge, $request)->refusal);
    }

    /** @return iterable<string, array{bool, ?float}> */
    public static function blockingReadings(): iterable
    {
        yield 'at its limit' => [true, null];
        yield 'at its limit with a use' => [true, 100.0];
        yield 'at its stop value' => [false, 95.0];
        yield 'above its stop value' => [false, 120.0];
    }

    #[DataProvider('blockingReadings')]
    public function test_a_blocked_account_is_refused_and_the_request_stays_open(bool $limited, ?float $usedPercent): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-blocked', WorkRequestState::Open);
        $this->seedAccountReading($this->em(), $bridge, '2026-10-01 12:00:00', usedPercent: $usedPercent, resetsAt: '2026-10-01 17:00:00', limited: $limited);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::AccountBlocked, $result->refusal);
        self::assertSame('account_blocked', $result->refusal->code());
        self::assertSame(409, $result->refusal->httpStatus());
        self::assertSame([], $changes->events());
        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    public function test_only_the_named_account_is_checked(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-named-account', WorkRequestState::Open);
        $bridge->accounts[] = ['name' => 'spare', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['implement']];
        $this->em()->flush();
        $this->seedAccountReading($this->em(), $bridge, '2026-10-01 12:00:00', usedPercent: null, resetsAt: '2026-10-01 17:00:00', limited: true);

        self::assertSame(WorkRequestRefusal::AccountBlocked, $this->claim($owner, $bridge, $request, 'work')->refusal);
        self::assertSame(WorkRequestState::Claimed, $this->claim($owner, $bridge, $request, 'spare')->request?->state);
    }

    public function test_an_account_whose_window_reset_is_not_blocked(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-reset', WorkRequestState::Open);
        $this->seedAccountReading($this->em(), $bridge, '2026-10-01 10:00:00', usedPercent: null, resetsAt: '2026-10-01 12:00:00', limited: true);

        self::assertNull($this->claim($owner, $bridge, $request)->refusal);
    }

    public function test_a_request_that_waits_for_a_time_is_refused_until_the_time_passes(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-waiting', WorkRequestState::Open);
        $request->waitUntil = new \DateTimeImmutable('2026-10-01 12:30:01');
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request);

        self::assertSame(WorkRequestRefusal::Waiting, $result->refusal);
        self::assertSame('waiting', $result->refusal->code());
        self::assertSame(409, $result->refusal->httpStatus());

        $request->waitUntil = new \DateTimeImmutable(self::NOW);
        $this->em()->flush();

        self::assertNull($this->claim($owner, $bridge, $request)->refusal);
    }

    public function test_a_held_card_is_refused_before_a_blocked_account(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-held-blocked', WorkRequestState::Open);
        $this->seedAccountReading($this->em(), $bridge, '2026-10-01 12:00:00', usedPercent: null, resetsAt: '2026-10-01 17:00:00', limited: true);
        $this->hold($request, $owner);

        self::assertSame(WorkRequestRefusal::CardHeld, $this->claim($owner, $bridge, $request)->refusal);
    }

    public function test_a_claim_clears_the_wait_and_the_matches_of_the_request(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-clears', WorkRequestState::Open);
        $other = $this->seedBridge($this->em(), $owner, projects: $bridge->projects);
        $request->waitCause = WorkRequest::WAIT_USAGE_LIMIT;
        $request->waitAccount = 'work';
        $this->em()->flush();
        $matches = self::getContainer()->get(BridgeWorkMatchRepository::class);
        self::assertInstanceOf(BridgeWorkMatchRepository::class, $matches);
        $ownerId = $owner->id ?? throw new \LogicException('The owner has an id.');
        $requestId = $request->id ?? throw new \LogicException('The request has an id.');
        $matches->put($ownerId, $bridge->id, $requestId, 'work');
        $matches->put($ownerId, $other->id, $requestId, 'work');

        $result = $this->claim($owner, $bridge, $request);

        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
        self::assertSame([null, null], [$result->request->waitCause, $result->request->waitAccount]);
        self::assertSame('0', (string) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_work_matches'));
    }

    #[DataProvider('accountsTheBridgeDoesNotRunForTheKind')]
    public function test_an_unknown_account_is_recorded_as_a_refusal_of_the_bridge_and_the_request(?string $account): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-refusal-row', WorkRequestState::Open);

        $result = $this->claim($owner, $bridge, $request, $account);
        $this->claim($owner, $bridge, $request, $account);

        self::assertSame(WorkRequestRefusal::AccountUnknown, $result->refusal);
        self::assertSame(
            [[(string) $bridge->id, (string) $request->id, $account]],
            $this->em()->getConnection()->fetchAllNumeric('SELECT bridge_id, work_request_id, account FROM bridge_claim_refusals'),
        );
    }

    public function test_a_second_refusal_with_another_account_replaces_the_account_of_the_row(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-refusal-replace', WorkRequestState::Open);

        $this->claim($owner, $bridge, $request, null);
        $this->claim($owner, $bridge, $request, 'other');

        self::assertSame([['other']], $this->em()->getConnection()->fetchAllNumeric('SELECT account FROM bridge_claim_refusals'));
    }

    public function test_a_missing_capability_records_no_refusal(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-refusal-capability', WorkRequestState::Open);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
        $request->capability = 'subject-analysis';
        $this->em()->flush();

        $result = $this->claim($owner, $bridge, $request, null);

        self::assertSame(WorkRequestRefusal::CapabilityMissing, $result->refusal);
        self::assertSame('0', (string) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_claim_refusals'));
    }

    public function test_a_claim_that_passes_the_account_check_removes_the_refusal_of_its_bridge_alone(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-refusal-passes', WorkRequestState::Open);
        $other = $this->seedBridge($this->em(), $owner, projects: $bridge->projects);
        $refusals = $this->refusals();
        $ownerId = $owner->id ?? throw new \LogicException('The owner has an id.');
        $requestId = $request->id ?? throw new \LogicException('The request has an id.');
        $refusals->put($ownerId, $bridge->id, $requestId, null);
        $refusals->put($ownerId, $other->id, $requestId, null);
        $this->hold($request, $owner);

        $result = $this->claim($owner, $bridge, $request);

        self::assertSame(WorkRequestRefusal::CardHeld, $result->refusal);
        self::assertSame([[(string) $other->id]], $this->em()->getConnection()->fetchAllNumeric('SELECT bridge_id FROM bridge_claim_refusals'));
    }

    public function test_a_claim_that_succeeds_removes_the_refusals_of_every_bridge_for_the_request(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-refusal-success', WorkRequestState::Open);
        $other = $this->seedBridge($this->em(), $owner, projects: $bridge->projects);
        $unrelated = $this->seedWorkRequest($this->em(), $request->project, kind: 'fix');
        $refusals = $this->refusals();
        $ownerId = $owner->id ?? throw new \LogicException('The owner has an id.');
        $requestId = $request->id ?? throw new \LogicException('The request has an id.');
        $refusals->put($ownerId, $bridge->id, $requestId, null);
        $refusals->put($ownerId, $other->id, $requestId, 'work');
        $refusals->put($ownerId, $other->id, $unrelated->id ?? throw new \LogicException('The request has an id.'), null);

        $result = $this->claim($owner, $bridge, $request);

        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
        self::assertSame([[(string) $unrelated->id]], $this->em()->getConnection()->fetchAllNumeric('SELECT work_request_id FROM bridge_claim_refusals'));
    }

    private function refusals(): BridgeClaimRefusalRepository
    {
        $refusals = self::getContainer()->get(BridgeClaimRefusalRepository::class);
        self::assertInstanceOf(BridgeClaimRefusalRepository::class, $refusals);

        return $refusals;
    }

    /** Writes the row alone, because a real hold cancels the open requests of its card. */
    private function hold(WorkRequest $request, User $owner): void
    {
        $this->em()->persist(new CardHold($request->project, $request->subjectId, $owner, new \DateTimeImmutable(self::NOW)));
        $this->em()->flush();
    }

    /** @return array{User, Bridge, WorkRequest} */
    private function scenario(string $name, WorkRequestState $state): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id?->toRfc4122()]);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $bridge->accounts = [['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['implement', 'fix', 'analysis:implement']]];
        $em->flush();

        return [$owner, $bridge, $this->seedWorkRequest($em, $project, state: $state)];
    }

    private function claim(User $owner, Bridge $bridge, WorkRequest $request, ?string $account = 'work'): ClaimWorkRequestResult
    {
        $handler = self::getContainer()->get(ClaimWorkRequestHandler::class);
        self::assertInstanceOf(ClaimWorkRequestHandler::class, $handler);

        return $handler(new ClaimWorkRequestCommand($owner, $bridge->id, $request->id ?? throw new \LogicException('A flushed request has an id.'), $account));
    }
}
