<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeWorkMatchRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkRequestRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    private const string LEASE_UNTIL = '2026-10-01 12:05:00';

    public function test_a_claim_takes_an_open_request_once(): void
    {
        self::bootKernel();
        $em = $this->em();
        $request = $this->seedWorkRequest($em, $this->project($em, $this->user($em, 'claim-once@example.com'), 'Claim Once'));
        $id = $request->id ?? throw new \LogicException('A flushed request has an id.');
        $bridgeId = Uuid::v4();
        $token = Uuid::v4();
        $leaseUntil = new \DateTimeImmutable(self::LEASE_UNTIL);

        self::assertTrue($this->repository()->claim($id, $bridgeId, $token, $leaseUntil));
        self::assertFalse($this->repository()->claim($id, Uuid::v4(), Uuid::v4(), $leaseUntil));

        $em->clear();
        $stored = $em->find(WorkRequest::class, $id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertSame(WorkRequestState::Claimed, $stored->state);
        self::assertSame((string) $bridgeId, (string) $stored->bridgeId);
        self::assertSame((string) $token, (string) $stored->claimToken);
        self::assertSame(self::LEASE_UNTIL, $stored->leaseUntil?->format('Y-m-d H:i:s'));
        self::assertSame(1, $stored->claims);
    }

    public function test_a_claim_of_a_settled_or_unknown_request_fails(): void
    {
        self::bootKernel();
        $em = $this->em();
        $request = $this->seedWorkRequest($em, $this->project($em, $this->user($em, 'claim-settled@example.com'), 'Claim Settled'), state: WorkRequestState::Cancelled);
        $leaseUntil = new \DateTimeImmutable(self::LEASE_UNTIL);

        self::assertFalse($this->repository()->claim($request->id ?? throw new \LogicException(), Uuid::v4(), Uuid::v4(), $leaseUntil));
        self::assertFalse($this->repository()->claim(Uuid::v7(), Uuid::v4(), Uuid::v4(), $leaseUntil));
    }

    /** The claim writes with native SQL, so the managed copy still reads open. */
    public function test_the_locked_read_refreshes_a_managed_request(): void
    {
        self::bootKernel();
        $em = $this->em();
        $request = $this->seedWorkRequest($em, $this->project($em, $this->user($em, 'claim-refresh@example.com'), 'Claim Refresh'));
        $id = $request->id ?? throw new \LogicException('A flushed request has an id.');
        $this->repository()->claim($id, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable(self::LEASE_UNTIL));
        self::assertSame(WorkRequestState::Open, $request->state);

        $state = $em->wrapInTransaction(fn (): ?WorkRequestState => $this->repository()->findOneLocked($id)?->state);

        self::assertSame(WorkRequestState::Claimed, $state);
    }

    public function test_a_renewal_needs_the_bridge_and_the_token_of_the_claim(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $owner = $this->user($em, 'renew@example.com'), 'Renew');
        $bridgeId = Uuid::v4();
        $first = $this->claimed($project, $bridgeId, $firstToken = Uuid::v4());
        $second = $this->claimed($project, $bridgeId, Uuid::v4());
        $other = $this->claimed($project, Uuid::v4(), $otherToken = Uuid::v4());
        $settledToken = Uuid::v4();
        $settled = $this->claimed($project, $bridgeId, $settledToken);
        $em->getConnection()->executeStatement("UPDATE work_requests SET state = 'done' WHERE id = ?", [(string) $settled->id]);
        $later = new \DateTimeImmutable('2026-10-01 12:10:00');

        $renewed = $this->repository()->renewLeases((string) $owner->id, $bridgeId, [
            [$this->idOf($first), $firstToken],
            [$this->idOf($second), Uuid::v4()],
            [$this->idOf($other), $otherToken],
            [$this->idOf($settled), $settledToken],
        ], $later);

        self::assertSame([(string) $first->id], $renewed);
        self::assertSame([], $this->repository()->renewLeases((string) $owner->id, $bridgeId, [], $later));
        $leases = $em->getConnection()->fetchAllKeyValue('SELECT id, lease_until FROM work_requests');
        self::assertSame('2026-10-01 12:10:00', $leases[(string) $first->id]);
        self::assertSame(self::LEASE_UNTIL, $leases[(string) $second->id]);
        self::assertSame(self::LEASE_UNTIL, $leases[(string) $other->id]);
    }

    /** Bridge rows are keyed by the owner and the id, so another account can report the same bridge id. */
    public function test_a_renewal_needs_the_owner_of_the_project(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'renew-owner@example.com'), 'Renew Owner');
        $intruder = $this->user($em, 'renew-intruder@example.com');
        $bridgeId = Uuid::v4();
        $claim = $this->claimed($project, $bridgeId, $token = Uuid::v4());

        $renewed = $this->repository()->renewLeases((string) $intruder->id, $bridgeId, [[$this->idOf($claim), $token]], new \DateTimeImmutable('2026-10-01 12:10:00'));

        self::assertSame([], $renewed);
        self::assertSame(self::LEASE_UNTIL, $em->getConnection()->fetchOne('SELECT lease_until FROM work_requests WHERE id = ?', [(string) $claim->id]));
    }

    public function test_offers_are_the_open_requests_of_the_projects_a_bridge_can_run_oldest_first(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'offers@example.com');
        $followed = $this->project($em, $owner, 'Followed');
        $alsoFollowed = $this->project($em, $owner, 'Also Followed');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-01 '.$time);
        $newer = $this->seedWorkRequest($em, $followed, createdAt: $at('12:05:00'));
        $older = $this->seedWorkRequest($em, $alsoFollowed, createdAt: $at('12:00:00'));
        $interactive = $this->seedWorkRequest($em, $followed, capability: 'interactive', createdAt: $at('12:10:00'));
        $this->seedWorkRequest($em, $followed, capability: 'gpu', createdAt: $at('11:00:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Claimed, createdAt: $at('11:00:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Done, createdAt: $at('11:00:00'));
        $this->seedWorkRequest($em, $this->project($em, $owner, 'Not Followed'), createdAt: $at('11:00:00'));
        $projects = [(string) $followed->id, (string) $alsoFollowed->id];

        self::assertSame([$older, $newer, $interactive], $this->repository()->findOpenOffers(Uuid::v4(), $projects, ['work-requests', 'interactive'], 10, []));
        self::assertSame([$older, $newer], $this->repository()->findOpenOffers(Uuid::v4(), $projects, [], 10, []));
        self::assertSame([$older], $this->repository()->findOpenOffers(Uuid::v4(), $projects, ['interactive'], 1, []));
        self::assertSame([], $this->repository()->findOpenOffers(Uuid::v4(), [], ['interactive'], 10, []));
    }

    public function test_a_request_with_a_target_is_offered_to_that_bridge_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'offers-target@example.com'), 'Offers Target');
        $target = Uuid::v4();
        $other = Uuid::v4();
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-01 '.$time);
        $untargeted = $this->seedWorkRequest($em, $project, createdAt: $at('12:00:00'));
        $targeted = $this->seedWorkRequest($em, $project, kind: 'design-export', capability: 'run-command', createdAt: $at('12:01:00'), targetBridgeId: $target, branch: 'main', params: ['out' => 'var/x']);
        $projects = [(string) $project->id];

        self::assertSame([$untargeted, $targeted], $this->repository()->findOpenOffers($target, $projects, ['run-command'], 10, []));
        self::assertSame([$untargeted], $this->repository()->findOpenOffers($other, $projects, ['run-command'], 10, []));
        $em->clear();
        $stored = $em->find(WorkRequest::class, $targeted->id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertSame([(string) $target, 'main', ['out' => 'var/x']], [(string) $stored->targetBridgeId, $stored->branch, $stored->params]);
    }

    public function test_offers_leave_out_a_request_that_waits_for_a_time_that_has_not_come(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'offers-wait-until@example.com'), 'Offers Wait Until');
        $now = new \DateTimeImmutable('2026-10-01 12:30:00');
        $free = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $due = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:01:00'));
        $waiting = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:02:00'));
        $due->waitUntil = $now;
        $waiting->waitUntil = $now->modify('+1 second');
        $em->flush();

        self::assertSame([$free, $due], $this->repository()->findOpenOffers(Uuid::v4(), [(string) $project->id], [], 10, [], $now));
    }

    public function test_offers_leave_out_a_request_matched_to_a_blocked_account_of_this_bridge_before_the_limit(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'offers-blocked-match@example.com');
        $project = $this->project($em, $owner, 'Offers Blocked Match');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $other = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-01 '.$time);
        $blocked = $this->seedWorkRequest($em, $project, createdAt: $at('12:00:00'));
        $spare = $this->seedWorkRequest($em, $project, createdAt: $at('12:01:00'));
        $unmatched = $this->seedWorkRequest($em, $project, createdAt: $at('12:02:00'));
        $matches = self::getContainer()->get(BridgeWorkMatchRepository::class);
        self::assertInstanceOf(BridgeWorkMatchRepository::class, $matches);
        $ownerId = $owner->id ?? throw new \LogicException('A flushed owner has an id.');
        $matches->put($ownerId, $bridge->id, $this->idOf($blocked), 'work');
        $matches->put($ownerId, $bridge->id, $this->idOf($spare), 'spare');
        $matches->put($ownerId, $other->id, $this->idOf($unmatched), 'work');
        $projects = [(string) $project->id];
        $now = $at('12:30:00');

        self::assertSame([$blocked, $spare, $unmatched], $this->repository()->findOpenOffers($bridge->id, $projects, [], 10, [], $now));
        self::assertSame([$spare, $unmatched], $this->repository()->findOpenOffers($bridge->id, $projects, [], 10, [], $now, ['work']));
        self::assertSame([$unmatched], $this->repository()->findOpenOffers($bridge->id, $projects, [], 10, [], $now, ['work', 'spare']));
        self::assertSame([$spare], $this->repository()->findOpenOffers($bridge->id, $projects, [], 1, [], $now, ['work']), 'The limit counts the requests that stay, so blocked work never crowds them out.');
        self::assertSame([$blocked, $spare], $this->repository()->findOpenOffers($other->id, $projects, [], 10, [], $now, ['work']), 'A match holds back the offer from its own bridge only.');
    }

    public function test_offers_leave_out_the_requests_of_held_cards(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'offers-held@example.com');
        $project = $this->project($em, $owner, 'Offers Held');
        $heldCard = Uuid::v7();
        $this->seedWorkRequest($em, $project, cardId: $heldCard);
        $free = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:01:00'));
        $subject = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:02:00'), subject: new WorkSubject('analysis', Uuid::v7()));
        $em->persist(new CardHold($project, $heldCard, $owner, new \DateTimeImmutable('2026-10-01 12:03:00')));
        $em->flush();

        self::assertSame([$free, $subject], $this->repository()->findOpenOffers(Uuid::v4(), [(string) $project->id], [], 10, []));
    }

    public function test_open_requests_of_a_card_leave_out_settled_and_claimed_ones(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-of-card@example.com'), 'Open Of Card');
        $cardId = Uuid::v7();
        $open = $this->seedWorkRequest($em, $project, cardId: $cardId);
        $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'fix', state: WorkRequestState::Claimed);
        $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'merge', state: WorkRequestState::Done);
        $this->seedWorkRequest($em, $project);

        self::assertSame([$open], $this->repository()->findOpenForCard($cardId));
    }

    public function test_app_prompts_receive_the_subject_requests_that_carry_a_prompt(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'offers-prompts@example.com'), 'Offers Prompts');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-01 '.$time);
        $withPrompt = $this->seedWorkRequest($em, $project, kind: 'analysis', capability: 'subject-analysis', createdAt: $at('12:00:00'), prompt: 'Run it.');
        $this->seedWorkRequest($em, $project, kind: 'analysis', capability: 'subject-analysis', createdAt: $at('12:01:00'));
        $this->seedWorkRequest($em, $project, capability: 'interactive', createdAt: $at('12:02:00'), prompt: 'Run it.');
        $projects = [(string) $project->id];

        self::assertSame([$withPrompt], $this->repository()->findOpenOffers(Uuid::v4(), $projects, ['work-requests', 'app-prompts'], 10, []));
        self::assertSame([], $this->repository()->findOpenOffers(Uuid::v4(), $projects, ['work-requests'], 10, []));
    }

    public function test_the_sweep_reopens_the_claims_whose_lease_lapsed(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'lapse@example.com'), 'Lapse');
        $lapsed = $this->claimed($project, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable('2026-10-01 12:00:00'));
        $held = $this->claimed($project, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable('2026-10-01 12:00:01'));
        $open = $this->seedWorkRequest($em, $project);

        $reopened = $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:00:00'), []);

        self::assertSame([$lapsed], $reopened);
        self::assertSame(WorkRequestState::Open, $lapsed->state);
        self::assertNull($lapsed->bridgeId);
        self::assertNull($lapsed->claimToken);
        self::assertNull($lapsed->leaseUntil);
        self::assertSame(1, $lapsed->claims);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:00:00'), $lapsed->reopenedAt);
        $em->clear();
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $held->id)?->state);
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $open->id)?->state);
        self::assertSame([], $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:00:00'), []));
    }

    public function test_live_rule_id_reads_the_rule_of_the_open_and_claimed_requests_of_a_card_and_kind(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'has-live@example.com'), 'Has Live');
        $open = $this->seedWorkRequest($em, $project, ruleId: 'open-rule');
        $claimed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, ruleId: 'claimed-rule');
        $done = $this->seedWorkRequest($em, $project, state: WorkRequestState::Done);

        self::assertSame('open-rule', $this->repository()->liveRuleId($open->subject(), 'implement'));
        self::assertNull($this->repository()->liveRuleId($open->subject(), 'design'));
        self::assertSame('claimed-rule', $this->repository()->liveRuleId($claimed->subject(), 'implement'));
        self::assertNull($this->repository()->liveRuleId($done->subject(), 'implement'));
    }

    /**
     * DAMA wraps the test in one transaction, and a failed statement aborts
     * it. The inserts run in a savepoint, and through DBAL, because a failed
     * flush closes the entity manager.
     */
    public function test_the_unique_index_allows_one_live_request_per_card_and_kind(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'unique@example.com'), 'Unique');
        $first = $this->seedWorkRequest($em, $project);
        $connection = $em->getConnection();
        $insert = static fn (string $kind) => $connection->insert('work_requests', [
            'id' => (string) Uuid::v7(),
            'project_id' => (string) $project->id,
            'subject_type' => 'card',
            'subject_id' => (string) $first->subjectId,
            'card_number' => 7,
            'kind' => $kind,
            'rule_id' => 'implement-on-entry',
            'state' => 'open',
            'created_at' => '2026-10-01 12:00:00',
        ]);

        $connection->beginTransaction();
        try {
            $insert('implement');
            self::fail('Expected the unique index to refuse a second live request.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString(WorkRequest::LIVE_SUBJECT_KIND_INDEX, $e->getMessage());
        } finally {
            $connection->rollBack();
        }

        $insert('design');
        $connection->executeStatement("UPDATE work_requests SET state = 'cancelled' WHERE id = ?", [(string) $first->id]);
        $insert('implement');

        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    private function claimed(Project $project, Uuid $bridgeId, Uuid $token, \DateTimeImmutable $leaseUntil = new \DateTimeImmutable(self::LEASE_UNTIL), ?Uuid $cardId = null, string $kind = 'implement'): WorkRequest
    {
        $request = $this->seedWorkRequest($this->em(), $project, $cardId, $kind);
        $request->state = WorkRequestState::Claimed;
        $request->bridgeId = $bridgeId;
        $request->claimToken = $token;
        $request->leaseUntil = $leaseUntil;
        $request->claims = 1;
        $this->em()->flush();

        return $request;
    }

    private function idOf(Project|WorkRequest $entity): Uuid
    {
        return $entity->id ?? throw new \LogicException('A flushed entity has an id.');
    }

    public function test_the_live_requests_of_a_card_read_oldest_first(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'live-for-card@example.com'), 'Live For Card');
        $cardId = Uuid::v7();
        $newer = $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'fix', state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 12:10:00'));
        $older = $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'implement', createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->seedWorkRequest($em, $project, cardId: $cardId, state: WorkRequestState::Done);
        $this->seedWorkRequest($em, $project, cardId: $cardId, state: WorkRequestState::Cancelled);
        $this->seedWorkRequest($em, $project);

        self::assertSame([$older, $newer], $this->repository()->findLiveForCard($cardId));
        self::assertSame([], $this->repository()->findLiveForCard(Uuid::v7()));
    }

    public function test_the_card_reads_skip_a_request_about_another_subject_with_the_same_id(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'other-subject@example.com'), 'Other Subject');
        $sharedId = Uuid::v7();
        $card = $this->seedWorkRequest($em, $project, cardId: $sharedId);
        $analysis = new WorkRequest($project, 'analysis', $sharedId, null, 'implement', null, 'insights.analysis', new \DateTimeImmutable('2026-10-01 11:00:00'));
        $em->persist($analysis);
        $em->flush();

        self::assertSame([$card], $this->repository()->findLiveForCard($sharedId));
        self::assertSame('insights.analysis', $this->repository()->liveRuleId($analysis->subject(), 'implement'));
        self::assertNull($this->repository()->liveRuleId(new WorkSubject('analysis', $sharedId), 'design'));
    }

    public function test_the_latest_settled_request_of_a_card_is_done_or_refused(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'latest-settled@example.com'), 'Latest Settled');
        $cardId = Uuid::v7();
        $settled = function (WorkRequestState $state, string $settledAt) use ($em, $project, $cardId): WorkRequest {
            $request = $this->seedWorkRequest($em, $project, cardId: $cardId, state: $state);
            $request->settledAt = new \DateTimeImmutable($settledAt);
            $em->flush();

            return $request;
        };
        $settled(WorkRequestState::Done, '2026-10-01 12:10:00');
        $latest = $settled(WorkRequestState::Refused, '2026-10-01 12:20:00');
        $settled(WorkRequestState::Cancelled, '2026-10-01 12:30:00');
        $settled(WorkRequestState::Expired, '2026-10-01 12:40:00');
        $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'fix', state: WorkRequestState::Claimed);
        $other = $this->seedWorkRequest($em, $project, state: WorkRequestState::Done);
        $other->settledAt = new \DateTimeImmutable('2026-10-01 13:00:00');
        $em->flush();

        self::assertSame($latest, $this->repository()->findLatestSettledForCard($cardId));
        self::assertNull($this->repository()->findLatestSettledForCard(Uuid::v7()));
    }

    public function test_the_latest_request_of_a_card_is_the_one_created_last_in_any_state(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'latest-request@example.com'), 'Latest Request');
        $cardId = Uuid::v7();
        $done = $this->seedWorkRequest($em, $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $done->settledAt = new \DateTimeImmutable('2026-10-01 14:00:00');
        $em->flush();
        $cancelled = $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'fix', state: WorkRequestState::Cancelled, createdAt: new \DateTimeImmutable('2026-10-01 13:00:00'));
        $this->seedWorkRequest($em, $project, state: WorkRequestState::Open, createdAt: new \DateTimeImmutable('2026-10-01 15:00:00'));

        self::assertSame($cancelled, $this->repository()->findLatestForCard($cardId));
        self::assertNull($this->repository()->findLatestForCard(Uuid::v7()));
    }

    public function test_the_open_requests_of_other_subjects_past_the_deadline_read_oldest_first(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'other-subjects@example.com'), 'Other Subjects');
        $analysis = static fn (): WorkSubject => new WorkSubject('analysis', Uuid::v7());
        $deadline = new \DateTimeImmutable('2026-10-01 10:00:00');
        $atTheEdge = $this->seedWorkRequest($em, $project, createdAt: $deadline, subject: $analysis());
        $older = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 09:00:00'), subject: $analysis());
        $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:01'), subject: $analysis());
        $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), subject: $analysis(), reopenedAt: new \DateTimeImmutable('2026-10-01 10:30:00'));
        $reopenedLongAgo = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 07:00:00'), subject: $analysis(), reopenedAt: new \DateTimeImmutable('2026-10-01 09:30:00'));
        $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), subject: $analysis());
        $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'));

        $found = $this->repository()->findOpenOfOtherSubjectsBefore($deadline);

        self::assertSame(
            [(string) $reopenedLongAgo->id, (string) $older->id, (string) $atTheEdge->id],
            array_map(static fn (WorkRequest $request): string => (string) $request->id, $found),
        );
    }

    private function repository(): WorkRequestRepository
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return $repository;
    }

    public function test_a_held_card_has_no_offer_and_no_reopened_claim_until_the_release(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'held-card-offers@example.com');
        $project = $this->project($em, $owner, 'Held Card Offers');
        $heldCard = Uuid::v7();
        $offered = $this->seedWorkRequest($em, $project);
        $this->seedWorkRequest($em, $project, cardId: $heldCard);
        $lapsed = $this->claimed($project, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable('2026-10-01 12:00:00'), $heldCard, 'review');
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($project, $heldCard, $owner);
        $em->clear();

        self::assertSame([(string) $offered->id], array_map(static fn (WorkRequest $request): string => (string) $request->id, $this->repository()->findOpenOffers(Uuid::v4(), [(string) $project->id], [], 10, [])));
        self::assertSame([], $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:30:00'), []));

        $holds->release($project, [$heldCard]);

        self::assertSame([(string) $lapsed->id], array_map(static fn (WorkRequest $request): string => (string) $request->id, $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:30:00'), [])));
        // The hold cancelled the open request of the card, so the release offers the reopened claim alone.
        self::assertEqualsCanonicalizing([(string) $offered->id, (string) $lapsed->id], array_map(static fn (WorkRequest $request): string => (string) $request->id, $this->repository()->findOpenOffers(Uuid::v4(), [(string) $project->id], [], 10, [])));
    }

    public function test_the_requests_of_a_held_project_have_no_offer_and_no_reopened_claim_whatever_their_subject(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'held-project-offers@example.com');
        $held = $this->project($em, $owner, 'Held Project Offers');
        $free = $this->project($em, $owner, 'Free Project Offers');
        $this->seedWorkRequest($em, $held);
        $this->seedWorkRequest($em, $held, subject: new WorkSubject('analysis', Uuid::v7()));
        $offered = $this->seedWorkRequest($em, $free);
        $lapsed = $this->claimed($held, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable('2026-10-01 12:00:00'), Uuid::v7(), 'review');
        $lapsedAnalysis = $this->seedWorkRequest(
            $em,
            $held,
            state: WorkRequestState::Claimed,
            bridgeId: Uuid::v4(),
            claimToken: Uuid::v4(),
            leaseUntil: new \DateTimeImmutable('2026-10-01 12:00:00'),
            subject: new WorkSubject('analysis', Uuid::v7()),
        );
        $projects = [(string) $held->id, (string) $free->id];
        $heldIds = [(string) $held->id];
        $ids = static fn (array $requests): array => array_map(static fn (WorkRequest $request): string => (string) $request->id, $requests);

        self::assertSame([(string) $offered->id], $ids($this->repository()->findOpenOffers(Uuid::v4(), $projects, [], 10, $heldIds)));
        self::assertSame([], $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:30:00'), $heldIds));
        self::assertSame([(string) $held->id], $this->repository()->findProjectIdsOfLapsedClaims(new \DateTimeImmutable('2026-10-01 12:30:00')));
        self::assertEqualsCanonicalizing([(string) $lapsed->id, (string) $lapsedAnalysis->id], $ids($this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:30:00'), [])));
    }

    public function test_the_done_fixes_are_told_apart_by_repository_state_and_role(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'done-fixes@example.com'), 'Done Fixes');
        $column = new BoardColumn($project, 'Implementation', 'implementation', 1);
        $card = new Card($project, $column, 'Card', '', 1);
        $em->persist($column);
        $em->persist($card);
        $em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/7', Forge::GitHub, 'Acme/Widgets', 7));
        $em->persist(new CardPullRequest($card, 'https://github.com/Acme/Gadgets/pull/7', Forge::GitHub, 'Acme/Gadgets', 7));
        $em->flush();
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');

        $this->fix($project, $card, '2026-10-19 14:00:00', 'https://github.com/Acme/Widgets/pull/7');
        $this->fix($project, $card, '2026-10-19 15:00:00', 'https://github.com/Acme/Widgets/pull/7');
        $this->fix($project, $card, '2026-10-19 16:00:00', 'https://github.com/Acme/Widgets/pull/7', state: WorkRequestState::Refused);
        $this->fix($project, $card, '2026-10-19 16:30:00', 'https://github.com/Acme/Widgets/pull/7', role: 'code-review');
        $this->fix($project, $card, '2026-10-19 17:00:00', 'https://github.com/Acme/Gadgets/pull/7');
        $this->fix($project, $card, '2026-10-19 18:00:00', 'https://github.com/Other/Thing/pull/7');
        $this->fix($project, $card, '2026-10-19 19:00:00', null);
        $em->flush();

        $found = $this->repository()->findFinishedFixesOpenedAt([$cardId])[$cardId->toRfc4122()] ?? [];
        usort($found, static fn (array $a, array $b): int => $a['repository'] <=> $b['repository']);

        self::assertSame(
            [['github', 'acme/gadgets', 7, '2026-10-19 17:00:00'], ['github', 'acme/widgets', 7, '2026-10-19 15:00:00']],
            array_map(static fn (array $fix): array => [$fix['forge'], $fix['repository'], $fix['number'], $fix['openedAt']->format('Y-m-d H:i:s')], $found),
        );
        self::assertSame([], $this->repository()->findFinishedFixesOpenedAt([]));
    }

    public function test_a_refused_fix_request_counts_when_a_run_of_it_succeeded(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'resumed-fix@example.com'), 'Resumed Fix');
        $column = new BoardColumn($project, 'Implementation', 'implementation', 1);
        $card = new Card($project, $column, 'Card', '', 1);
        $em->persist($column);
        $em->persist($card);
        $em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/7', Forge::GitHub, 'Acme/Widgets', 7));
        $em->flush();
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $url = 'https://github.com/Acme/Widgets/pull/7';

        $resumed = $this->fix($project, $card, '2026-10-21 01:01:00', $url, WorkRequestState::Refused);
        $blocked = $this->fix($project, $card, '2026-10-21 02:00:00', $url, WorkRequestState::Refused);
        $stopped = $this->fix($project, $card, '2026-10-21 03:00:00', $url, WorkRequestState::Open);
        $review = $this->fix($project, $card, '2026-10-21 04:00:00', $url, WorkRequestState::Refused, 'code-review');
        $em->flush();
        $this->workerRun($project, $card, $resumed, WorkerRunState::Blocked);
        $this->workerRun($project, $card, $resumed, WorkerRunState::Succeeded);
        $this->workerRun($project, $card, $blocked, WorkerRunState::Blocked);
        $this->workerRun($project, $card, $stopped, WorkerRunState::Failed);
        $this->workerRun($project, $card, $review, WorkerRunState::Succeeded);
        $em->flush();

        $found = $this->repository()->findFinishedFixesOpenedAt([$cardId])[$cardId->toRfc4122()] ?? [];

        self::assertSame(['2026-10-21 01:01:00'], array_map(static fn (array $fix): string => $fix['openedAt']->format('Y-m-d H:i:s'), $found));
    }

    public function test_the_counts_of_cards_group_every_request_by_role_kind_and_reason(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'counts-of-cards@example.com'), 'Counts Of Cards');
        $card = Uuid::v7();
        $none = Uuid::v7();
        $request = function (Uuid $card, string $kind, ?string $role, ?string $reason, WorkRequestState $state = WorkRequestState::Done, string $subjectType = WorkSubject::CARD) use ($em, $project): void {
            $row = $this->seedWorkRequest($em, $project, $card, $kind, state: $state, subject: new WorkSubject($subjectType, $card));
            $row->role = $role;
            $row->context = new WorkRequestContext(reason: $reason);
        };
        $request($card, 'fix', 'fix', 'conflict');
        $request($card, 'fix', 'fix', 'conflict', WorkRequestState::Cancelled);
        $request($card, 'fix', 'fix', 'checks-failed');
        $request($card, 'fix', 'fix', null);
        $request($card, 'implement', null, null);
        $request($card, 'review', 'code-review', null, WorkRequestState::Open, 'document');
        $em->flush();

        $counts = $this->repository()->findCountsOfCards([$card, $none]);

        $key = $card->toRfc4122();
        self::assertSame([
            ['cardId' => $key, 'role' => 'fix', 'kind' => 'fix', 'reason' => 'checks-failed', 'count' => 1],
            ['cardId' => $key, 'role' => 'fix', 'kind' => 'fix', 'reason' => 'conflict', 'count' => 2],
            ['cardId' => $key, 'role' => 'fix', 'kind' => 'fix', 'reason' => 'unknown', 'count' => 1],
            ['cardId' => $key, 'role' => null, 'kind' => 'implement', 'reason' => 'unknown', 'count' => 1],
        ], $counts);
        self::assertSame([], $this->repository()->findCountsOfCards([]));
    }

    private function workerRun(Project $project, Card $card, WorkRequest $request, WorkerRunState $state): void
    {
        $this->em()->persist(new WorkerRun(
            project: AgentCredential::managed($this->em(), $project, $project->id),
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: 1,
            workKind: 'fix',
            state: $state,
            receivedAt: new \DateTimeImmutable('2026-10-21 05:00:00'),
            workRequestId: $request->id,
        ));
    }

    private function fix(Project $project, Card $card, string $openedAt, ?string $url, WorkRequestState $state = WorkRequestState::Done, string $role = 'fix'): WorkRequest
    {
        $request = $this->seedWorkRequest($this->em(), $project, $card->id, 'fix', state: $state, createdAt: new \DateTimeImmutable($openedAt), ruleId: 'fix-on-review');
        $request->role = $role;
        $request->context = new WorkRequestContext(pullRequestNumber: 7, pullRequestUrl: $url, reason: 'changes-requested');

        return $request;
    }
}
