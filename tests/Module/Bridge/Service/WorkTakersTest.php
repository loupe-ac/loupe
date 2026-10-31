<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeWorkMatch;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\AccountLimitCause;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class WorkTakersTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-16 12:00:00';

    public function test_a_project_with_no_bridge_has_no_taker_from_the_start_of_the_wait(): void
    {
        [$project, $request] = $this->scenario('no-bridge');

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::None, $reading->state);
        self::assertEquals($request->createdAt, $reading->noTakerSince);
        self::assertNull($reading->quietAt);
    }

    public function test_capable_for_reads_a_kind_with_no_request(): void
    {
        [$project, , $owner] = $this->scenario('capable-for');

        self::assertSame(WorkTakerState::None, $this->service()->capableFor($project, 'discovery', true)->state);

        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['fix']);
        $reading = $this->service()->capableFor($project, 'discovery', true);
        self::assertSame([WorkTakerState::NotCapable, true], [$reading->state, $reading->appPromptsMissing]);

        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['discovery']);
        self::assertSame(WorkTakerState::Available, $this->service()->capableFor($project, 'discovery', false)->state);
    }

    public function test_capable_for_takes_app_prompts_only_for_work_that_ships_a_prompt(): void
    {
        [$project, , $owner] = $this->scenario('capable-for-prompts');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: [], capabilities: [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS]);

        self::assertSame(WorkTakerState::Available, $this->service()->capableFor($project, 'discovery', true)->state);
        self::assertSame(WorkTakerState::NotCapable, $this->service()->capableFor($project, 'discovery', false)->state);
    }

    public function test_an_online_bridge_that_runs_the_kind_is_a_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('available');
        $this->bridge($owner, $project, seenSecondsAgo: 10);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_the_answer_changes_one_second_after_the_newest_online_taker_turns_quiet(): void
    {
        [$project, $request, $owner] = $this->scenario('quiet-at');
        $this->bridge($owner, $project, seenSecondsAgo: 100);
        $newest = $this->bridge($owner, $project, seenSecondsAgo: 10);

        $reading = $this->read($project, $request);

        self::assertEquals($newest->lastSeenAt->modify(\sprintf('+%d seconds', $this->window() + 1)), $reading->quietAt);
    }

    public function test_a_bridge_seen_exactly_at_the_quiet_limit_is_still_online(): void
    {
        [$project, $request, $owner] = $this->scenario('limit');
        $this->bridge($owner, $project, seenSecondsAgo: $this->window());

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_bridge_seen_one_second_past_the_quiet_limit_is_quiet(): void
    {
        [$project, $request, $owner] = $this->scenario('past-limit');
        $this->bridge($owner, $project, seenSecondsAgo: $this->window() + 1);

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
    }

    public function test_the_wait_counts_from_the_moment_the_last_taker_went_quiet(): void
    {
        [$project, $request, $owner] = $this->scenario('went-quiet', createdAt: '2026-10-16 08:00:00');
        $quiet = $this->bridge($owner, $project, seenSecondsAgo: 3600);

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::None, $reading->state);
        self::assertEquals($quiet->lastSeenAt->modify(\sprintf('+%d seconds', $this->window())), $reading->noTakerSince);
    }

    public function test_the_wait_counts_from_the_request_when_the_bridge_went_quiet_before_it(): void
    {
        [$project, $request, $owner] = $this->scenario('quiet-before', createdAt: '2026-10-16 11:50:00');
        $this->bridge($owner, $project, seenSecondsAgo: 7200);

        self::assertEquals($request->createdAt, $this->read($project, $request)->noTakerSince);
    }

    public function test_a_reopened_request_counts_from_its_reopening(): void
    {
        [$project, $request] = $this->scenario('reopened', createdAt: '2026-10-16 08:00:00', reopenedAt: '2026-10-16 11:00:00');

        self::assertEquals(new \DateTimeImmutable('2026-10-16 11:00:00'), $this->read($project, $request)->noTakerSince);
    }

    public function test_a_bridge_paused_by_a_person_or_by_its_own_report_is_paused(): void
    {
        [$project, $request, $owner] = $this->scenario('paused');
        $bridge = $this->bridge($owner, $project, seenSecondsAgo: 10);

        $bridge->pauseRequested = true;
        $this->em()->flush();
        self::assertSame(WorkTakerState::Paused, $this->read($project, $request)->state);

        $bridge->pauseRequested = false;
        $bridge->pausedReported = true;
        $this->em()->flush();
        self::assertSame(WorkTakerState::Paused, $this->read($project, $request)->state);

        $bridge->pausedReported = false;
        $this->em()->flush();
        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_one_unpaused_online_bridge_beats_a_paused_one(): void
    {
        [$project, $request, $owner] = $this->scenario('mixed');
        $paused = $this->bridge($owner, $project, seenSecondsAgo: 10);
        $paused->pausedReported = true;
        $this->bridge($owner, $project, seenSecondsAgo: 20);
        $this->em()->flush();

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_paused_bridge_that_went_quiet_leaves_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('paused-quiet');
        $paused = $this->bridge($owner, $project, seenSecondsAgo: 3600);
        $paused->pauseRequested = true;
        $this->em()->flush();

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
    }

    public function test_a_bridge_without_the_kind_is_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('other-kind');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['fix']);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $request)->state);
    }

    public function test_a_bridge_that_reports_no_kinds_is_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('no-kinds');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: []);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $request)->state);
    }

    public function test_a_bridge_with_app_prompts_takes_every_kind_whose_request_carries_a_prompt(): void
    {
        [$project, $request, $owner] = $this->scenario('app-prompts', prompt: 'Build {cardId}.');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: [], capabilities: [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS]);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    /** @return iterable<string, array{?string}> */
    public static function noPrompt(): iterable
    {
        yield 'no prompt' => [null];
        yield 'a blank prompt' => ["  \n"];
    }

    #[DataProvider('noPrompt')]
    public function test_a_bridge_with_app_prompts_takes_a_request_with_no_prompt_only_by_its_kind(?string $prompt): void
    {
        [$project, $request, $owner] = $this->scenario('app-prompts-no-prompt', prompt: $prompt);
        $appPrompts = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['fix'], capabilities: $appPrompts);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $request)->state);

        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['implement'], capabilities: $appPrompts);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_the_wait_counts_from_the_moment_an_online_bridge_dropped_what_it_takes(): void
    {
        [$project, $request, $owner] = $this->scenario('narrowed', createdAt: '2026-10-16 08:00:00');
        $narrowed = $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['fix']);
        $narrowed->narrowedAt = new \DateTimeImmutable('2026-10-16 11:58:00');
        $this->em()->flush();

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::NotCapable, $reading->state);
        self::assertEquals($narrowed->narrowedAt, $reading->noTakerSince);
    }

    public function test_a_bridge_that_left_the_project_still_bounds_the_wait(): void
    {
        [$project, $request, $owner] = $this->scenario('left', createdAt: '2026-10-16 08:00:00');
        $left = $this->bridge($owner, $project, seenSecondsAgo: 10, follows: false);
        $left->narrowedAt = new \DateTimeImmutable('2026-10-16 11:58:00');
        $this->em()->flush();

        self::assertEquals($left->narrowedAt, $this->read($project, $request)->noTakerSince);
    }

    public function test_a_narrowing_before_the_wait_leaves_the_start_of_the_wait(): void
    {
        [$project, $request, $owner] = $this->scenario('narrowed-before', createdAt: '2026-10-16 11:00:00');
        $narrowed = $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['fix']);
        $narrowed->narrowedAt = new \DateTimeImmutable('2026-10-16 10:00:00');
        $this->em()->flush();

        self::assertEquals($request->createdAt, $this->read($project, $request)->noTakerSince);
    }

    public function test_a_bridge_without_the_capability_of_the_request_is_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('capability', capability: 'interactive');
        $this->bridge($owner, $project, seenSecondsAgo: 10);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $request)->state);
    }

    public function test_a_request_about_another_subject_needs_the_subject_capability_and_its_kind_on_that_subject(): void
    {
        [$project, $request, $owner] = $this->scenario('subject', capability: 'subject-analysis', subject: new WorkSubject('analysis', Uuid::v7()));
        $analysis = [Bridge::CAPABILITY_WORK_REQUESTS, 'subject-analysis'];
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['analysis:implement']);
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['analysis:report', 'implement'], capabilities: $analysis);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $request)->state);

        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['analysis:implement'], capabilities: $analysis);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_bridge_with_app_prompts_takes_a_request_about_another_subject_that_carries_a_prompt(): void
    {
        [$project, $request, $owner] = $this->scenario('subject-prompt', capability: 'subject-analysis', prompt: 'Analyse.', subject: new WorkSubject('analysis', Uuid::v7()));
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['analysis:report'], capabilities: [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS]);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    /** The CLI runs the app prompt when the entry of the kind names another subject, as TestAppPromptRunsWhenTheEntryNamesAnotherSubject shows. */
    public function test_a_bridge_with_app_prompts_takes_a_prompted_kind_that_its_entry_maps_to_another_subject(): void
    {
        $appPrompts = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS, 'subject-analysis'];
        [$project, $request, $owner] = $this->scenario('mapped-elsewhere-analysis', capability: 'subject-analysis', prompt: 'Analyse.', subject: new WorkSubject('analysis', Uuid::v7()));
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['implement'], capabilities: $appPrompts);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);

        [$project, $request, $owner] = $this->scenario('mapped-elsewhere-card', prompt: 'Build {cardId}.');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['analysis:implement'], capabilities: $appPrompts);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_card_kind_of_another_subject_takes_no_card_request(): void
    {
        [$project, $request, $owner] = $this->scenario('card-kind-of-subject');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: ['analysis:implement'], capabilities: [Bridge::CAPABILITY_WORK_REQUESTS, 'subject-analysis']);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $request)->state);
    }

    public function test_a_bridge_that_does_not_follow_the_project_is_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('not-followed');
        $this->bridge($owner, $project, seenSecondsAgo: 10, follows: false);

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
    }

    public function test_a_claimed_request_whose_bridge_went_quiet_reads_as_disconnected_until_its_lease_ends(): void
    {
        [$project, $request, $owner] = $this->scenario('claimed-quiet', state: WorkRequestState::Claimed);
        $bridge = $this->bridge($owner, $project, seenSecondsAgo: $this->window() + 60);
        $request->bridgeId = $bridge->id;
        $request->leaseUntil = new \DateTimeImmutable('2026-10-16 12:20:00');
        $this->em()->flush();

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Disconnected, $reading->state);
        self::assertTrue($reading->claimed);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:20:00'), $reading->quietAt);
    }

    public function test_a_claimed_request_whose_bridge_still_sends_heartbeats_has_no_reading(): void
    {
        [$project, $request, $owner] = $this->scenario('claimed-alive', state: WorkRequestState::Claimed);
        $bridge = $this->bridge($owner, $project, seenSecondsAgo: $this->window());
        $request->bridgeId = $bridge->id;
        $request->leaseUntil = new \DateTimeImmutable('2026-10-16 12:20:00');
        $this->em()->flush();

        self::assertSame([], $this->service()->forRequests($project, [$request]));
    }

    public function test_an_open_request_that_waits_on_the_network_reads_as_disconnected_until_the_wait_ends(): void
    {
        [$project, $request, $owner] = $this->scenario('network-wait', reopenedAt: '2026-10-16 11:58:00');
        $this->bridge($owner, $project, seenSecondsAgo: 10);
        $request->waitCause = 'network';
        $request->waitUntil = new \DateTimeImmutable('2026-10-16 12:03:00');
        $this->em()->flush();

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Disconnected, $reading->state);
        self::assertFalse($reading->claimed);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:03:00'), $reading->quietAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 11:58:00'), $reading->noTakerSince);
    }

    public function test_a_network_wait_that_has_ended_reads_like_any_open_request(): void
    {
        [$project, $request, $owner] = $this->scenario('network-ended');
        $this->bridge($owner, $project, seenSecondsAgo: 10);
        $request->waitCause = 'network';
        $request->waitUntil = new \DateTimeImmutable('2026-10-16 11:59:00');
        $this->em()->flush();

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_network_wait_reads_as_disconnected_whether_or_not_a_bridge_is_online(): void
    {
        [$project, $request] = $this->scenario('network-no-bridge');
        $request->waitCause = 'network';
        $request->waitUntil = new \DateTimeImmutable('2026-10-16 12:03:00');
        $this->em()->flush();

        self::assertSame(WorkTakerState::Disconnected, $this->read($project, $request)->state);
    }

    public function test_a_usage_limit_wait_is_not_a_disconnection(): void
    {
        [$project, $request, $owner] = $this->scenario('usage-wait');
        $this->bridge($owner, $project, seenSecondsAgo: 10);
        $request->waitCause = 'usage-limit';
        $this->em()->flush();

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_claimed_request_has_no_reading(): void
    {
        [$project, $request] = $this->scenario('claimed', state: WorkRequestState::Claimed);

        self::assertSame([], $this->service()->forRequests($project, [$request]));
    }

    public function test_a_request_is_limited_when_every_account_that_runs_its_kind_is_at_its_limit(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-all');
        $bridge = $this->bridge($owner, $project, 10);
        $this->accounts($bridge, ['one' => ['implement'], 'two' => ['implement']]);
        $this->reading($bridge, 'one', 'week', 100.0, '2026-10-20 17:00:00', true);
        $this->reading($bridge, 'two', 'session', null, '2026-10-17 09:00:00', true);

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Limited, $reading->state);
        self::assertNotNull($reading->limit);
        self::assertSame('two', $reading->limit->releasing->account);
        self::assertSame('session', $reading->limit->window->reading->window);
        self::assertNull($reading->limit->window->reading->usedPercent);
        self::assertSame(AccountLimitCause::Limit, $reading->limit->cause());
        self::assertEquals(new \DateTimeImmutable('2026-10-17 09:00:00', new \DateTimeZone('UTC')), $reading->limit->resetsAt);
        self::assertEquals(new \DateTimeImmutable(self::NOW)->modify(\sprintf('-10 seconds +%d seconds +1 second', $this->window())), $reading->quietAt, 'the bridge turns quiet before the reset');
        self::assertCount(2, $reading->limit->accounts);
    }

    public function test_an_account_at_its_stop_value_holds_the_request_with_the_cause_stop_until_its_latest_blocking_reset(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-stop');
        $bridge = $this->bridge($owner, $project, 10);
        $this->accounts($bridge, ['work' => ['implement']]);
        $this->reading($bridge, 'work', 'session', 96.0, '2026-10-16 15:00:00', false);
        $this->reading($bridge, 'work', 'week', 97.0, '2026-10-20 15:00:00', false);

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Limited, $reading->state);
        self::assertSame(AccountLimitCause::Stop, $reading->limit?->cause());
        self::assertSame('week', $reading->limit->window->reading->window);
        self::assertEquals(new \DateTimeImmutable('2026-10-20 15:00:00', new \DateTimeZone('UTC')), $reading->limit->resetsAt);
    }

    public function test_a_request_reads_available_while_an_account_that_runs_its_kind_has_room(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-room');
        $bridge = $this->bridge($owner, $project, 10);
        $this->accounts($bridge, ['one' => ['implement'], 'two' => ['implement'], 'other' => ['review']]);
        $this->reading($bridge, 'one', 'week', 100.0, '2026-10-20 17:00:00', true);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_bridge_that_reports_no_kinds_per_account_is_not_limited(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-old-bridge');
        $bridge = $this->bridge($owner, $project, 10);
        $bridge->accounts = [['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null]];
        $this->em()->flush();
        $this->reading($bridge, 'work', 'week', 100.0, '2026-10-20 17:00:00', true);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_the_account_the_bridge_matched_decides_even_when_another_account_has_room(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-match');
        $bridge = $this->bridge($owner, $project, 10);
        $this->accounts($bridge, ['one' => ['implement'], 'two' => ['implement']]);
        $this->reading($bridge, 'one', 'week', 100.0, '2026-10-20 17:00:00', true);
        $this->match($bridge, $request, 'one');

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Limited, $reading->state);
        self::assertNotNull($reading->limit);
        self::assertSame(['one'], array_map(static fn ($account) => $account->account, $reading->limit->accounts));
    }

    public function test_a_request_matched_to_an_account_with_room_reads_available(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-match-room');
        $bridge = $this->bridge($owner, $project, 10);
        $this->accounts($bridge, ['one' => ['implement'], 'two' => ['implement']]);
        $this->reading($bridge, 'one', 'week', 100.0, '2026-10-20 17:00:00', true);
        $this->reading($bridge, 'two', 'week', 10.0, '2026-10-20 17:00:00', false);
        $this->match($bridge, $request, 'two');

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_a_limit_that_already_reset_blocks_nothing(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-reset');
        $bridge = $this->bridge($owner, $project, 10);
        $this->accounts($bridge, ['work' => ['implement']]);
        $this->reading($bridge, 'work', 'week', 100.0, '2026-10-16 11:59:59', true);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);
    }

    public function test_another_bridge_with_room_makes_the_request_available_and_the_first_release_speaks_for_the_wait(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-two-bridges');
        $limited = $this->bridge($owner, $project, 10);
        $this->accounts($limited, ['work' => ['implement']]);
        $this->reading($limited, 'work', 'week', 100.0, '2026-10-20 17:00:00', true);
        $free = $this->bridge($owner, $project, 20);
        $this->accounts($free, ['work' => ['implement']]);

        self::assertSame(WorkTakerState::Available, $this->read($project, $request)->state);

        $this->reading($free, 'work', 'week', 100.0, '2026-10-18 17:00:00', true);
        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Limited, $reading->state);
        self::assertNotNull($reading->limit);
        self::assertEquals(new \DateTimeImmutable('2026-10-18 17:00:00', new \DateTimeZone('UTC')), $reading->limit->resetsAt);
        self::assertCount(2, $reading->limit->accounts);
    }

    public function test_a_request_bound_to_a_bridge_counts_that_bridge_alone(): void
    {
        [$project, , $owner] = $this->scenario('limited-target');
        $limited = $this->bridge($owner, $project, 10);
        $this->accounts($limited, ['work' => ['implement']]);
        $this->reading($limited, 'work', 'week', 100.0, '2026-10-20 17:00:00', true);
        $free = $this->bridge($owner, $project, 20);
        $this->accounts($free, ['work' => ['implement']]);
        $targeted = $this->seedWorkRequest($this->em(), $project, createdAt: new \DateTimeImmutable('2026-10-16 11:00:00'), targetBridgeId: $limited->id);

        self::assertSame(WorkTakerState::Limited, $this->read($project, $targeted)->state);
    }

    public function test_a_request_bound_to_a_bridge_that_dropped_its_kind_is_not_available_on_another_bridge(): void
    {
        [$project, , $owner] = $this->scenario('target-dropped-kind');
        $target = $this->bridge($owner, $project, 10, workKinds: ['fix']);
        $this->bridge($owner, $project, 20);
        $targeted = $this->seedWorkRequest($this->em(), $project, createdAt: new \DateTimeImmutable('2026-10-16 11:00:00'), targetBridgeId: $target->id);

        self::assertSame(WorkTakerState::NotCapable, $this->read($project, $targeted)->state);
    }

    public function test_a_request_bound_to_a_bridge_that_left_the_project_is_not_available_on_another_bridge(): void
    {
        [$project, , $owner] = $this->scenario('target-left');
        $target = $this->bridge($owner, $project, 10, follows: false);
        $this->bridge($owner, $project, 20);
        $targeted = $this->seedWorkRequest($this->em(), $project, createdAt: new \DateTimeImmutable('2026-10-16 11:00:00'), targetBridgeId: $target->id);

        self::assertNotSame(WorkTakerState::Available, $this->read($project, $targeted)->state);
    }

    public function test_a_paused_bridge_does_not_count_as_limited_and_a_free_bridge_beats_a_limited_one(): void
    {
        [$project, $request, $owner] = $this->scenario('limited-paused');
        $limited = $this->bridge($owner, $project, 10);
        $this->accounts($limited, ['work' => ['implement']]);
        $this->reading($limited, 'work', 'week', 100.0, '2026-10-20 17:00:00', true);
        $paused = $this->bridge($owner, $project, 20);
        $paused->pauseRequested = true;
        $this->em()->flush();

        $reading = $this->read($project, $request);

        self::assertSame(WorkTakerState::Limited, $reading->state);
        self::assertNotNull($reading->limit);
        self::assertCount(1, $reading->limit->accounts);
    }

    /** @return array{Project, WorkRequest, User} */
    private function scenario(string $name, string $createdAt = '2026-10-16 11:00:00', ?string $reopenedAt = null, ?string $capability = null, WorkRequestState $state = WorkRequestState::Open, ?string $prompt = null, ?WorkSubject $subject = null): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, 'work-takers-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Takers '.$name);
        $request = $this->seedWorkRequest($em, $project, capability: $capability, state: $state, createdAt: new \DateTimeImmutable($createdAt), subject: $subject, reopenedAt: null === $reopenedAt ? null : new \DateTimeImmutable($reopenedAt), prompt: $prompt);

        return [$project, $request, $owner];
    }

    /**
     * @param list<string>      $workKinds
     * @param list<string>|null $capabilities
     */
    private function bridge(User $owner, Project $project, int $seenSecondsAgo, array $workKinds = ['implement'], ?array $capabilities = null, bool $follows = true): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner, projects: $follows ? [(string) $project->id] : [], lastSeenAt: new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d seconds', $seenSecondsAgo)));
        $bridge->capabilities = $capabilities ?? [Bridge::CAPABILITY_WORK_REQUESTS];
        $bridge->workKinds = $workKinds;
        $this->em()->flush();

        return $bridge;
    }

    /** @param array<string, list<string>> $kinds the work kinds each account runs, by account name */
    private function accounts(Bridge $bridge, array $kinds): void
    {
        $bridge->accounts = array_map(static fn (string $name, array $runs): array => ['name' => $name, 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => $runs], array_keys($kinds), array_values($kinds));
        $this->em()->flush();
    }

    private function reading(Bridge $bridge, string $account, string $window, ?float $usedPercent, string $resetsAt, bool $limited): void
    {
        $this->seedAccountReading($this->em(), $bridge, '2026-10-16 11:00:00', $account, $window, $usedPercent, $resetsAt, $limited);
    }

    private function match(Bridge $bridge, WorkRequest $request, string $account): void
    {
        $this->em()->persist(new BridgeWorkMatch(Uuid::v7(), $bridge, $request, $account));
        $this->em()->flush();
    }

    private function read(Project $project, WorkRequest $request): WorkTakerReading
    {
        $readings = $this->service()->forRequests($project, [$request]);

        return $readings[($request->id ?? throw new \LogicException('Stored.'))->toRfc4122()] ?? throw new \LogicException('No reading.');
    }

    private function window(): int
    {
        $liveness = self::getContainer()->get(BridgeLiveness::class);
        self::assertInstanceOf(BridgeLiveness::class, $liveness);
        $now = new \DateTimeImmutable(self::NOW);

        return $now->getTimestamp() - $liveness->quietBefore($now)->getTimestamp();
    }

    private function service(): WorkTakers
    {
        $service = self::getContainer()->get(WorkTakers::class);
        self::assertInstanceOf(WorkTakers::class, $service);

        return $service;
    }
}
