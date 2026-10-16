<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

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

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
    }

    public function test_a_bridge_that_reports_no_kinds_is_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('no-kinds');
        $this->bridge($owner, $project, seenSecondsAgo: 10, workKinds: []);

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
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

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);

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

        self::assertSame(WorkTakerState::None, $reading->state);
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

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
    }

    public function test_a_bridge_that_does_not_follow_the_project_is_no_taker(): void
    {
        [$project, $request, $owner] = $this->scenario('not-followed');
        $this->bridge($owner, $project, seenSecondsAgo: 10, follows: false);

        self::assertSame(WorkTakerState::None, $this->read($project, $request)->state);
    }

    public function test_a_claimed_request_has_no_reading(): void
    {
        [$project, $request] = $this->scenario('claimed', state: WorkRequestState::Claimed);

        self::assertSame([], $this->service()->forRequests($project, [$request]));
    }

    /** @return array{Project, WorkRequest, User} */
    private function scenario(string $name, string $createdAt = '2026-10-16 11:00:00', ?string $reopenedAt = null, ?string $capability = null, WorkRequestState $state = WorkRequestState::Open, ?string $prompt = null): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, 'work-takers-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Takers '.$name);
        $request = $this->seedWorkRequest($em, $project, capability: $capability, state: $state, createdAt: new \DateTimeImmutable($createdAt), reopenedAt: null === $reopenedAt ? null : new \DateTimeImmutable($reopenedAt), prompt: $prompt);

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
