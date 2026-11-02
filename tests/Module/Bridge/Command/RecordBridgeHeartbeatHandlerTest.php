<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Bridge\Event\BridgeReported;
use App\Module\Bridge\Repository\BridgeClaimRefusalRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\Service\HostSampling;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordedEvents;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class RecordBridgeHeartbeatHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    /** @var list<Update> */
    private array $runsChanged = [];

    public function test_the_first_heartbeat_of_a_bridge_is_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-audit-first@example.com');
        $project = $this->project($em, $owner, 'Audited Heartbeat');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [(string) $project->id], 'b4e39aa7'));

        $record = $audit->record('bridge.bridge_registered');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['bridgeId' => (string) $bridgeId, 'projects' => 1], $record->context);
    }

    /** A replace arrives once a minute per bridge, so it must leave the trail alone. */
    public function test_a_heartbeat_that_replaces_the_row_is_not_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-audit-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
        self::assertCount(1, $audit->records('bridge.bridge_registered'));

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertCount(1, $audit->records('bridge.bridge_registered'));
        self::assertSame(['bridge.bridge_registered'], $audit->operations());
    }

    public function test_it_stores_the_update_report_and_answers_the_cli_range(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update-report@example.com');

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, Uuid::v4(), [], '1.2.0', CliUpdateState::Blocked, '1.3.0'));

        self::assertSame(CliCompatibility::RANGE, $result->cliRange);
        self::assertSame(CliUpdateState::Blocked, $result->bridge->updateState);
        self::assertSame('1.3.0', $result->bridge->updateVersion);
    }

    public function test_each_heartbeat_replaces_the_install_method(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-install-method@example.com');
        $bridgeId = Uuid::v4();

        $first = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], '1.2.0', CliUpdateState::Off, '1.3.0', installMethod: CliInstallMethod::Homebrew));
        self::assertSame(CliInstallMethod::Homebrew, $first->bridge->installMethod);

        $second = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], '1.2.0', CliUpdateState::Current));

        self::assertNull($second->bridge->installMethod);
    }

    public function test_the_first_heartbeat_stores_the_hook_report(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-first@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));

        self::assertSame([self::hook()], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_a_later_heartbeat_replaces_the_hook_report(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $failed = ['outcome' => 'failed', 'error' => 'exit 1'] + self::hook();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [$failed]));

        self::assertSame([$failed], $this->reload($owner, $bridgeId)->hooks);
    }

    /** An older bridge sends no report, and its heartbeat must not clear the rows a newer one sent. */
    public function test_a_heartbeat_without_a_hook_report_keeps_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertSame([self::hook()], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_an_empty_hook_report_clears_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: []));

        self::assertSame([], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_a_row_written_before_the_hooks_column_reads_as_no_hooks(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-legacy@example.com');
        $bridgeId = Uuid::v4();
        $em->getConnection()->executeStatement(
            'INSERT INTO bridges (owner_id, id, projects, cli_version, last_seen_at) VALUES (?, ?, ?, ?, ?)',
            [(string) $owner->id, (string) $bridgeId, '[]', 'b4e39aa7', '2026-09-14 16:00:00'],
        );

        self::assertSame([], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_a_change_of_the_worker_pools_tells_the_pages_of_the_followed_project_to_reload(): void
    {
        self::bootKernel();
        $this->recordRunsChanged();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-publish@example.com');
        $project = $this->project($em, $owner, 'Followed');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $projects = [(string) $project->id];
        $busy = ['inUse' => 3] + self::pool();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', workerPools: [self::pool()]));
        self::assertCount(1, $this->publishedRunsChanged(), 'The first report of pools changes what the pill shows.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', workerPools: [self::pool()]));
        self::assertCount(0, $this->publishedRunsChanged(), 'The same pools tell nobody.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', workerPools: [$busy]));
        $published = $this->publishedRunsChanged();
        self::assertCount(1, $published);
        self::assertStringContainsString((string) $project->id, $published[0]->getTopics()[0]);

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7'));
        self::assertCount(0, $this->publishedRunsChanged(), 'A heartbeat with no pool rows changes nothing.');
    }

    public function test_a_new_reading_tells_the_pages_of_the_followed_project_to_reload(): void
    {
        self::bootKernel();
        $this->recordRunsChanged();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-publish@example.com');
        $project = $this->project($em, $owner, 'Followed');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $projects = [(string) $project->id];

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:00:00')]));
        self::assertCount(1, $this->publishedRunsChanged(), 'The first reading changes the bars.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:01:00')]));
        self::assertCount(1, $this->publishedRunsChanged(), 'The same values read later change the age that the page shows.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:01:00')]));
        self::assertCount(0, $this->publishedRunsChanged(), 'A resent reading changes nothing and tells nobody.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', accountReadings: [self::reading('session', 41.0, readAt: '2026-10-07 12:02:00')]));
        $published = $this->publishedRunsChanged();
        self::assertCount(1, $published, 'A reading with another use changes the bars.');
        self::assertStringContainsString((string) $project->id, $published[0]->getTopics()[0]);
    }

    /** The Agents pill drops a quiet bridge, so its first heartbeat back counts its workers again. */
    public function test_a_bridge_back_from_quiet_tells_the_pages_to_reload_with_the_same_pools(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-09-23 12:00:00'));
        $this->recordRunsChanged();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-back-from-quiet@example.com');
        $projects = [(string) $this->project($em, $owner, 'Followed')->id];
        $quiet = $this->seedBridge($em, $owner, projects: $projects, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:56:59'), workerPools: [self::pool()]);
        $live = $this->seedBridge($em, $owner, projects: $projects, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:57:00'), workerPools: [self::pool()]);
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $live->id, $projects, 'b4e39aa7', workerPools: [self::pool()]));
        self::assertSame([], $this->publishedProjects(), 'A live bridge with the same pools tells nobody.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $quiet->id, $projects, 'b4e39aa7', workerPools: [self::pool()]));
        self::assertSame($projects, $this->publishedProjects());
    }

    public function test_a_change_of_the_followed_projects_tells_the_pages_of_both_sets_to_reload(): void
    {
        self::bootKernel();
        $this->recordRunsChanged();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-follow-publish@example.com');
        $kept = (string) $this->project($em, $owner, 'Kept')->id;
        $added = (string) $this->project($em, $owner, 'Added')->id;
        $both = [$kept, $added];
        sort($both);
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$kept], 'b4e39aa7', workerPools: [self::pool()]));
        $this->publishedRunsChanged();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$kept, $added], 'b4e39aa7', workerPools: [self::pool()]));
        self::assertSame($both, $this->publishedProjects(), 'A newly followed project counts the bridge.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$kept], 'b4e39aa7', workerPools: [self::pool()]));
        self::assertSame($both, $this->publishedProjects(), 'A project the bridge left stops counting it.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$kept], 'b4e39aa7', workerPools: [self::pool()]));
        self::assertSame([], $this->publishedProjects(), 'The same projects tell nobody.');
    }

    public function test_the_first_heartbeat_of_a_bridge_announces_a_change_of_availability_and_a_routine_one_announces_none(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-availability-first@example.com');
        $projects = [(string) $this->project($em, $owner, 'Availability First')->id];
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $announced = $this->recordAvailability();
        $beat = static fn (): RecordBridgeHeartbeatCommand => new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', paused: false, capabilities: [Bridge::CAPABILITY_WORK_REQUESTS], workKinds: ['implement']);

        $handler($beat());
        self::assertSame([$projects], self::announcedProjects($announced), 'The first beat of a new bridge announces.');

        $handler($beat());
        $handler($beat());
        self::assertCount(1, self::announcedProjects($announced), 'A routine beat announces nothing.');
    }

    public function test_a_bridge_back_from_quiet_announces_a_change_of_availability(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-10-16 12:00:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-availability-quiet@example.com');
        $projects = [(string) $this->project($em, $owner, 'Availability Quiet')->id];
        $live = $this->seedBridge($em, $owner, projects: $projects, lastSeenAt: new \DateTimeImmutable('2026-10-16 11:59:00'));
        $quiet = $this->seedBridge($em, $owner, projects: $projects, lastSeenAt: new \DateTimeImmutable('2026-10-16 11:00:00'));
        $handler = $this->handler();
        $announced = $this->recordAvailability();

        $handler(new RecordBridgeHeartbeatCommand($owner, $live->id, $projects, 'b4e39aa7'));
        self::assertSame([], self::announcedProjects($announced), 'A live bridge announces nothing.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $quiet->id, $projects, 'b4e39aa7'));
        self::assertSame([$projects], self::announcedProjects($announced));
    }

    public function test_a_change_of_the_reported_pause_the_capabilities_or_the_work_kinds_announces_a_change_of_availability(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-availability-changes@example.com');
        $projects = [(string) $this->project($em, $owner, 'Availability Changes')->id];
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $handler(self::beat($owner, $bridgeId, $projects, false, [Bridge::CAPABILITY_WORK_REQUESTS], ['implement']));
        $announced = $this->recordAvailability();

        $handler(self::beat($owner, $bridgeId, $projects, true, [Bridge::CAPABILITY_WORK_REQUESTS], ['implement']));
        self::assertCount(1, self::announcedProjects($announced), 'A pause announces.');

        $handler(self::beat($owner, $bridgeId, $projects, true, [Bridge::CAPABILITY_WORK_REQUESTS], ['implement']));
        $handler(self::beat($owner, $bridgeId, $projects, null, null, ['implement']));
        self::assertCount(1, self::announcedProjects($announced), 'The same pause and a beat with no report announce nothing.');

        $handler(self::beat($owner, $bridgeId, $projects, true, [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_INTERACTIVE], ['implement']));
        self::assertCount(2, self::announcedProjects($announced), 'Other capabilities announce.');

        $handler(self::beat($owner, $bridgeId, $projects, true, [Bridge::CAPABILITY_INTERACTIVE, Bridge::CAPABILITY_WORK_REQUESTS], ['implement']));
        self::assertCount(2, self::announcedProjects($announced), 'The order of the capabilities does not count.');

        $handler(self::beat($owner, $bridgeId, $projects, true, [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_INTERACTIVE], ['implement', 'fix']));
        self::assertCount(3, self::announcedProjects($announced), 'Other work kinds announce.');
    }

    public function test_a_change_of_the_reported_repositories_announces_a_change_of_availability(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-availability-repositories@example.com');
        $projects = [(string) $this->project($em, $owner, 'Availability Repositories')->id];
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $first = ['projectId' => $projects[0], 'path' => '/work/main', 'remoteUrl' => 'git@github.com:acme/loupe.git'];
        $moved = ['projectId' => $projects[0], 'path' => '/work/other', 'remoteUrl' => 'git@github.com:acme/other.git'];
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', repositories: [$first]));
        $announced = $this->recordAvailability();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', repositories: [$first]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7'));
        self::assertSame([], self::announcedProjects($announced), 'The same repositories and a beat with no report announce nothing.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', repositories: [$moved]));
        self::assertSame([$projects], self::announcedProjects($announced), 'Another folder announces.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', repositories: []));
        self::assertCount(2, self::announcedProjects($announced), 'A bridge that reports no folder announces.');
    }

    public function test_a_change_of_the_followed_projects_announces_a_change_of_availability_for_both_sets(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-availability-follow@example.com');
        $kept = (string) $this->project($em, $owner, 'Availability Kept')->id;
        $added = (string) $this->project($em, $owner, 'Availability Added')->id;
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$kept], 'b4e39aa7'));
        $announced = $this->recordAvailability();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$added], 'b4e39aa7'));

        self::assertCount(1, self::announcedProjects($announced));
        self::assertEqualsCanonicalizing([$kept, $added], self::announcedProjects($announced)[0]);
    }

    public function test_an_online_bridge_that_drops_a_kind_a_capability_or_a_project_records_when_it_narrowed(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-10-16 12:00:00');
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-narrowed@example.com');
        $kept = (string) $this->project($em, $owner, 'Narrowed Kept')->id;
        $left = (string) $this->project($em, $owner, 'Narrowed Left')->id;
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $both = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
        $narrowedAt = fn (): ?\DateTimeImmutable => $this->reload($owner, $bridgeId)->narrowedAt;

        $handler(self::beat($owner, $bridgeId, [$kept, $left], false, $both, ['implement']));
        $handler(self::beat($owner, $bridgeId, [$kept, $left], false, $both, ['implement', 'fix']));
        self::assertNull($narrowedAt(), 'A first beat and a wider beat narrow nothing.');

        foreach ([
            'a dropped kind' => [[$kept, $left], $both, ['implement']],
            'a dropped capability' => [[$kept, $left], [Bridge::CAPABILITY_WORK_REQUESTS], ['implement']],
            'a dropped project' => [[$kept], [Bridge::CAPABILITY_WORK_REQUESTS], ['implement']],
        ] as $case => [$projects, $capabilities, $kinds]) {
            $clock->sleep(30);
            $handler(self::beat($owner, $bridgeId, $projects, false, $capabilities, $kinds));
            self::assertEquals($clock->now(), $narrowedAt(), $case);
        }

        $clock->sleep(3600);
        $handler(self::beat($owner, $bridgeId, [], false, [], []));
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:01:30'), $narrowedAt(), 'A bridge back from quiet already left the work when it went quiet.');
    }

    public function test_the_first_heartbeat_stores_the_worker_pools(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-first@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));

        self::assertSame([self::pool()], $this->reload($owner, $bridgeId)->workerPools);
    }

    public function test_a_first_heartbeat_without_worker_pools_stores_none(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-none@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertNull($this->reload($owner, $bridgeId)->workerPools);
    }

    public function test_a_later_heartbeat_replaces_the_worker_pools(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $busy = ['inUse' => 3, 'queued' => 4] + self::pool();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [$busy]));

        self::assertSame([$busy], $this->reload($owner, $bridgeId)->workerPools);
    }

    /** An older bridge sends no pools, and its heartbeat must not clear the rows a newer one sent. */
    public function test_a_heartbeat_without_worker_pools_keeps_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertSame([self::pool()], $this->reload($owner, $bridgeId)->workerPools);
    }

    /** The Agents page dates the counts, so a heartbeat that carried none must not make them look fresh. */
    public function test_a_heartbeat_without_worker_pools_keeps_the_time_of_the_last_report(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-09-14 16:00:00');
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-time@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('2026-09-14 16:01:00', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-14 16:00:00', $bridge->workerPoolsReportedAt?->format('Y-m-d H:i:s'));

        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: []));

        self::assertSame('2026-09-14 16:02:00', $this->reload($owner, $bridgeId)->workerPoolsReportedAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_bridge_that_never_reported_pools_has_no_report_time(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-no-time@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertNull($this->reload($owner, $bridgeId)->workerPoolsReportedAt);
    }

    public function test_an_empty_worker_pool_report_clears_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: []));

        self::assertSame([], $this->reload($owner, $bridgeId)->workerPools);
    }

    public function test_a_bridge_that_never_reported_accounts_stores_none(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-none@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertNull($bridge->accounts);
        self::assertNull($bridge->accountsReportedAt);
    }

    public function test_an_account_report_replaces_the_stored_rows_and_stamps_the_time(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-09-14 16:00:00');
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $failing = ['state' => 'failing', 'reason' => 'not logged in'] + self::account();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accounts: [self::account()]));
        self::assertSame([self::account()], $this->reload($owner, $bridgeId)->accounts);

        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accounts: [$failing]));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame([$failing], $bridge->accounts);
        self::assertSame('2026-09-14 16:01:00', $bridge->accountsReportedAt?->format('Y-m-d H:i:s'));
    }

    /** An older bridge sends no accounts, and its heartbeat must neither clear the rows nor make them look fresh. */
    public function test_a_heartbeat_without_accounts_keeps_the_rows_and_their_time(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-09-14 16:00:00');
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accounts: [self::account()]));
        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('2026-09-14 16:01:00', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
        self::assertSame([self::account()], $bridge->accounts);
        self::assertSame('2026-09-14 16:00:00', $bridge->accountsReportedAt?->format('Y-m-d H:i:s'));
    }

    public function test_an_empty_account_report_clears_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accounts: [self::account()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accounts: []));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame([], $bridge->accounts);
        self::assertNotNull($bridge->accountsReportedAt);
    }

    public function test_command_entries_and_worktrees_are_replaced_kept_and_cleared(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-09-14 16:00:00');
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-entries-handler@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $entry = ['name' => 'design-export', 'description' => null, 'params' => [], 'run' => ['true'], 'timeout' => '10m'];
        $worktree = ['projectId' => (string) Uuid::v7(), 'branch' => 'main', 'path' => '/work/main'];

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
        $bridge = $this->reload($owner, $bridgeId);
        self::assertNull($bridge->commandEntries);
        self::assertNull($bridge->worktrees);
        self::assertNull($bridge->commandEntriesReportedAt);

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', commandEntries: [$entry], worktrees: [$worktree]));
        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame([$entry], $bridge->commandEntries);
        self::assertSame([$worktree], $bridge->worktrees);
        self::assertSame('2026-09-14 16:00:00', $bridge->commandEntriesReportedAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-14 16:00:00', $bridge->worktreesReportedAt?->format('Y-m-d H:i:s'));

        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', commandEntries: [], worktrees: []));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame([], $bridge->commandEntries);
        self::assertSame([], $bridge->worktrees);
        self::assertSame('2026-09-14 16:02:00', $bridge->commandEntriesReportedAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-14 16:02:00', $bridge->worktreesReportedAt?->format('Y-m-d H:i:s'));
    }

    public function test_repositories_are_replaced_kept_and_cleared(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-09-14 16:00:00');
        self::getContainer()->set('clock', $clock);
        $owner = $this->user($this->em(), 'heartbeat-repositories-handler@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $repository = ['projectId' => (string) Uuid::v7(), 'path' => '/work/main', 'remoteUrl' => 'git@github.com:acme/loupe.git'];

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
        $bridge = $this->reload($owner, $bridgeId);
        self::assertNull($bridge->repositories);
        self::assertNull($bridge->repositoriesReportedAt);

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', repositories: [$repository]));
        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame([$repository], $bridge->repositories);
        self::assertSame('2026-09-14 16:00:00', $bridge->repositoriesReportedAt?->format('Y-m-d H:i:s'));

        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', repositories: []));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame([], $bridge->repositories);
        self::assertSame('2026-09-14 16:02:00', $bridge->repositoriesReportedAt?->format('Y-m-d H:i:s'));
    }

    public function test_every_heartbeat_announces_the_projects_it_touched(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-reported@example.com');
        $project = $this->project($this->em(), $owner, 'Reported');
        $reported = new RecordedEvents();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BridgeReported::class, $reported);

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, Uuid::v4(), [(string) $project->id], 'b4e39aa7'));

        self::assertSame([[(string) $project->id]], array_map(static fn (BridgeReported $event): array => $event->projectIds, $reported->of(BridgeReported::class)));
    }

    public function test_a_first_heartbeat_answers_no_commands_and_stores_the_reports(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-first-commands@example.com');
        $bridgeId = Uuid::v4();

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', paused: true, capabilities: ['commands']));

        self::assertSame([], $result->commands);
        self::assertFalse($result->bridge->pauseRequested);
        $stored = $this->reload($owner, $bridgeId);
        self::assertTrue($stored->pausedReported);
        self::assertSame(['commands'], $stored->capabilities);
    }

    public function test_a_later_heartbeat_answers_the_pending_commands_of_the_bridge(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-09-29 12:10:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-later-commands@example.com');
        $project = $this->project($em, $owner, 'Heartbeat Handler Commands');
        $bridgeId = Uuid::v4();
        $this->seedBridge($em, $owner, $bridgeId);
        $pending = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId));
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), state: BridgeCommandState::Refused);

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertSame([(string) $pending->id], array_map(static fn (BridgeCommand $c): string => (string) $c->id, $result->commands));
    }

    public function test_a_heartbeat_without_a_name_keeps_the_stored_names(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('laptop', $bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
    }

    public function test_an_empty_name_clears_both_names(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: ''));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertNull($bridge->name);
        self::assertNull($bridge->requestedName);
        self::assertFalse($bridge->nameClashes());
    }

    public function test_a_free_name_is_taken(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-free@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('laptop', $bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
        self::assertFalse($bridge->nameClashes());
    }

    public function test_a_bridge_that_holds_its_name_keeps_it(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-hold@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));

        self::assertSame('laptop', $this->reload($owner, $bridgeId)->name);
    }

    public function test_a_name_another_bridge_holds_is_requested_and_not_taken(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-clash@example.com');
        $holder = Uuid::v4();
        $claimer = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $holder, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $claimer, [], 'b4e39aa7', name: 'laptop'));

        $bridge = $this->reload($owner, $claimer);
        self::assertNull($bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
        self::assertTrue($bridge->nameClashes());
        self::assertSame('laptop', $this->reload($owner, $holder)->name);
    }

    public function test_a_name_its_holder_gives_up_goes_to_the_claimer_at_its_next_heartbeat(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-freed@example.com');
        $holder = Uuid::v4();
        $claimer = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $holder, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $claimer, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $holder, [], 'b4e39aa7', name: 'desktop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $claimer, [], 'b4e39aa7', name: 'laptop'));

        self::assertSame('desktop', $this->reload($owner, $holder)->name);
        $bridge = $this->reload($owner, $claimer);
        self::assertSame('laptop', $bridge->name);
        self::assertFalse($bridge->nameClashes());
    }

    public function test_bridges_of_different_owners_never_clash(): void
    {
        self::bootKernel();
        $em = $this->em();
        $first = $this->user($em, 'heartbeat-name-owner-one@example.com');
        $second = $this->user($em, 'heartbeat-name-owner-two@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($first, Uuid::v4(), [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($second, $bridgeId, [], 'b4e39aa7', name: 'laptop'));

        self::assertSame('laptop', $this->reload($second, $bridgeId)->name);
    }

    /** @return array{name: string, harness: string, state: 'ready'|'failing', reason: ?string, used: bool} */
    private static function account(): array
    {
        return ['name' => 'work', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'used' => true];
    }

    /** @return array{name: string, size: int, inUse: int, queued: int} */
    private static function pool(): array
    {
        return ['name' => 'default', 'size' => 3, 'inUse' => 1, 'queued' => 0];
    }

    /** @return array{package: string, ref: string, event: string, lastRunAt: ?string, outcome: string, error: ?string} */
    private static function hook(): array
    {
        return [
            'package' => 'github:acme/loupe-hooks',
            'ref' => 'v1.2.0',
            'event' => 'start',
            'lastRunAt' => '2026-09-14T16:00:00+00:00',
            'outcome' => 'ok',
            'error' => null,
        ];
    }

    public function test_the_first_heartbeat_stores_its_samples_under_the_owner(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-10-07 12:00:30'));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-owner@example.com');
        $other = $this->user($em, 'heartbeat-samples-other@example.com');
        $bridgeId = Uuid::v4();
        $this->seedBridge($em, $other, $bridgeId);

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hostSamples: [self::sample('2026-10-07 12:00:00')]));

        self::assertSame(
            [['owner_id' => (string) $owner->id, 'bridge_id' => (string) $bridgeId, 'sampled_at' => '2026-10-07 12:00:00']],
            $em->getConnection()->fetchAllAssociative('SELECT owner_id, bridge_id, sampled_at FROM bridge_host_samples'),
        );
    }

    public function test_samples_are_dropped_while_sampling_is_off(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->storeHostSampling('false');
        $owner = $this->user($em, 'heartbeat-samples-dropped@example.com');
        $bridgeId = Uuid::v4();

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hostSamples: [self::sample('2026-10-07 12:00:00')]));

        self::assertSame('b4e39aa7', $result->bridge->cliVersion);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_host_samples'));
    }

    /** The run ended before its samples arrived, so the heartbeat must rewrite its fact row. */
    public function test_samples_rewrite_the_fact_of_an_ended_run_on_the_bridge(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-01-01 12:30:00'));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-facts@example.com');
        $project = $this->project($em, $owner, 'Sampled Facts');
        $bridge = $this->seedBridge($em, $owner);
        $inside = $this->seedRun($em, $project, bridgeId: $bridge->id);
        $outside = $this->seedRun($em, $project, cardNumber: 2, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('2026-01-01 12:05:00'));
        $em->getConnection()->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 12:00:00' WHERE id = ?", [(string) $outside->id]);
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET peak_mem_bytes = 1 WHERE run_id = ?', [(string) $outside->id]);

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', hostSamples: [
            self::sample('2026-01-01 10:01:00'),
            self::sample('2026-01-01 10:20:00'),
        ]));

        self::assertEquals(
            [(string) $inside->id => 1000, (string) $outside->id => 1],
            $em->getConnection()->fetchAllKeyValue('SELECT run_id, peak_mem_bytes FROM bridge_worker_run_facts'),
        );
    }

    public function test_a_sample_from_beyond_the_retention_window_is_dropped_and_widens_no_recompute(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-01-01 12:30:00', new \DateTimeZone('UTC'));
        self::getContainer()->set('clock', new MockClock($now));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-old@example.com');
        $project = $this->project($em, $owner, 'Old Samples');
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id);
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET peak_mem_bytes = 1 WHERE run_id = ?', [(string) $run->id]);
        $days = $this->retention()->retentionDays();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', hostSamples: [
            self::sample($now->modify('-'.$days.' days -1 minute')->format('Y-m-d H:i:s')),
            self::sample('2026-01-01 12:00:00'),
        ]));
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT peak_mem_bytes FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $run->id]));
        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', hostSamples: [
            self::sample($now->modify('-'.$days.' days +1 minute')->format('Y-m-d H:i:s')),
        ]));

        self::assertSame(
            [$now->modify('-'.$days.' days +1 minute')->format('Y-m-d H:i:s'), '2026-01-01 12:00:00'],
            $em->getConnection()->fetchFirstColumn('SELECT sampled_at FROM bridge_host_samples ORDER BY sampled_at'),
        );
    }

    public function test_a_sample_more_than_five_minutes_ahead_of_the_server_clock_is_dropped(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-10-07 12:00:00'));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-future@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hostSamples: [
            self::sample('2026-10-07 12:04:59'),
            self::sample('2026-10-07 12:05:01'),
        ]));

        self::assertSame(
            ['2026-10-07 12:04:59'],
            $em->getConnection()->fetchFirstColumn('SELECT sampled_at FROM bridge_host_samples'),
        );
    }

    /** A recompute that failed after the commit must run again when the bridge resends the batch. */
    public function test_a_resent_batch_recomputes_the_facts_again(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-01-01 12:30:00'));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-resend@example.com');
        $project = $this->project($em, $owner, 'Resent Samples');
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id);
        $command = new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', hostSamples: [self::sample('2026-01-01 10:01:00')]);
        $this->handler()($command);
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET peak_mem_bytes = NULL WHERE run_id = ?', [(string) $run->id]);

        $this->handler()($command);

        self::assertSame(1000, (int) $em->getConnection()->fetchOne('SELECT peak_mem_bytes FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $run->id]));
    }

    /** The run ended before its account readings arrived, with no host samples, so the readings alone must rewrite its fact row. */
    public function test_account_readings_rewrite_the_usage_of_an_ended_run_even_when_they_store_nothing(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-01-01 12:30:00'));
        $em = $this->em();
        $this->storeHostSampling('false');
        $owner = $this->user($em, 'heartbeat-readings-facts@example.com');
        $project = $this->project($em, $owner, 'Reading Facts');
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, account: 'work');
        $delta = static fn (): mixed => $em->getConnection()->fetchOne('SELECT session_usage_delta FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $run->id]);
        self::assertNull($delta());

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', accountReadings: [
            self::reading('session', 20.0, readAt: '2026-01-01 10:01:00'),
            self::reading('session', 35.0, readAt: '2026-01-01 10:04:00'),
        ]));
        self::assertEqualsWithDelta(15.0, (float) $delta(), 0.001);

        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET session_usage_delta = NULL WHERE run_id = ?', [(string) $run->id]);
        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', accountReadings: [
            self::reading('session', 35.0, readAt: '2026-01-01 10:04:30'),
        ]));
        self::assertEqualsWithDelta(15.0, (float) $delta(), 0.001, 'A reading equal to the latest stores nothing and still recomputes.');
    }

    public function test_a_heartbeat_keeps_the_refusals_of_open_requests_and_drops_those_of_closed_ones(): void
    {
        [$owner, $bridge, $open] = $this->refusalScenario('heartbeat-refusals-closed');

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, $bridge->projects, 'b4e39aa7', accounts: [self::account()]));

        self::assertSame([[(string) $open->id]], $this->em()->getConnection()->fetchAllNumeric('SELECT DISTINCT work_request_id FROM bridge_claim_refusals'));
    }

    public function test_a_heartbeat_with_a_new_cli_version_drops_every_refusal_of_the_bridge(): void
    {
        [$owner, $bridge, , , $otherBridge] = $this->refusalScenario('heartbeat-refusals-version');

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, $bridge->projects, '1.33.0', accounts: [self::account()]));

        self::assertSame([[(string) $otherBridge->id]], $this->em()->getConnection()->fetchAllNumeric('SELECT DISTINCT bridge_id FROM bridge_claim_refusals'));
    }

    public function test_a_heartbeat_with_new_accounts_drops_every_refusal_of_the_bridge(): void
    {
        [$owner, $bridge, , , $otherBridge] = $this->refusalScenario('heartbeat-refusals-accounts');

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, $bridge->projects, 'b4e39aa7', accounts: [self::account(), ['name' => 'spare', 'harness' => 'claude', 'state' => 'ready', 'reason' => null]]));

        self::assertSame([[(string) $otherBridge->id]], $this->em()->getConnection()->fetchAllNumeric('SELECT DISTINCT bridge_id FROM bridge_claim_refusals'));
    }

    public function test_a_heartbeat_that_omits_the_accounts_keeps_the_refusals(): void
    {
        [$owner, $bridge] = $this->refusalScenario('heartbeat-refusals-omitted');

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, $bridge->projects, 'b4e39aa7'));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_claim_refusals WHERE bridge_id = ?', [(string) $bridge->id]));
    }

    public function test_a_heartbeat_with_the_same_accounts_and_version_keeps_the_refusals_of_open_requests(): void
    {
        [$owner, $bridge, $open] = $this->refusalScenario('heartbeat-refusals-same');

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, $bridge->projects, 'b4e39aa7', accounts: [self::account()]));

        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_claim_refusals WHERE work_request_id = ?', [(string) $open->id]));
    }

    /**
     * The bridge has refusals for an open and a closed request, and a second bridge has one for the open request.
     *
     * @return array{User, Bridge, WorkRequest, WorkRequest, Bridge}
     */
    private function refusalScenario(string $name): array
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.$name);
        $projects = [(string) $project->id];
        $bridge = $this->seedBridge($em, $owner, projects: $projects, cliVersion: 'b4e39aa7', accounts: [self::account()]);
        $otherBridge = $this->seedBridge($em, $owner, projects: $projects);
        $open = $this->seedWorkRequest($em, $project);
        $closed = $this->seedWorkRequest($em, $project, kind: 'fix', state: WorkRequestState::Done);
        $refusals = self::getContainer()->get(BridgeClaimRefusalRepository::class);
        self::assertInstanceOf(BridgeClaimRefusalRepository::class, $refusals);
        $ownerId = $owner->id ?? throw new \LogicException('The owner has an id.');
        $refusals->put($ownerId, $bridge->id, $open->id ?? throw new \LogicException('The request has an id.'), null);
        $refusals->put($ownerId, $bridge->id, $closed->id ?? throw new \LogicException('The request has an id.'), 'work');
        $refusals->put($ownerId, $otherBridge->id, $open->id, null);

        return [$owner, $bridge, $open, $closed, $otherBridge];
    }

    private function retention(): WorkerRunRetentionPolicy
    {
        $retention = self::getContainer()->get(WorkerRunRetentionPolicy::class);
        self::assertInstanceOf(WorkerRunRetentionPolicy::class, $retention);

        return $retention;
    }

    private static function sample(string $at): BridgeHostSampleReport
    {
        return new BridgeHostSampleReport(new \DateTimeImmutable($at, new \DateTimeZone('UTC')), [10.0, 30.0], 1000, 4000, 0, null, true);
    }

    private function storeHostSampling(string $value): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [HostSampling::ENABLED_FLAG]);
        $connection->executeStatement(
            "INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', ?, '[]', NULL)",
            [HostSampling::ENABLED_FLAG, $value],
        );
    }

    private function reload(User $owner, Uuid $bridgeId): Bridge
    {
        $this->em()->clear();
        $bridges = self::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        $bridge = $bridges->findOneByOwnerAndId($owner, $bridgeId);
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }

    /**
     * @param list<string>      $projects
     * @param list<string>|null $capabilities
     * @param list<string>      $kinds
     */
    private static function beat(User $owner, Uuid $bridgeId, array $projects, ?bool $paused, ?array $capabilities, array $kinds): RecordBridgeHeartbeatCommand
    {
        return new RecordBridgeHeartbeatCommand($owner, $bridgeId, $projects, 'b4e39aa7', paused: $paused, capabilities: $capabilities, workKinds: $kinds);
    }

    private function recordAvailability(): RecordedEvents
    {
        $announced = new RecordedEvents();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BridgeAvailabilityChanged::class, $announced);

        return $announced;
    }

    private function recordReadings(): RecordedEvents
    {
        $announced = new RecordedEvents();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BridgeAccountReadingsStored::class, $announced);

        return $announced;
    }

    /** @return list<list<string>> */
    private static function announcedProjects(RecordedEvents $announced): array
    {
        return array_map(static fn (BridgeAvailabilityChanged $event): array => $event->projectIds, $announced->of(BridgeAvailabilityChanged::class));
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

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[LiveUpdates::FLAG]->value = true;
        $this->em()->flush();
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    /** @return list<Update> the runs-changed updates sent since the last call */
    private function publishedRunsChanged(): array
    {
        $live = self::getContainer()->get(LiveUpdatePublisher::class);
        self::assertInstanceOf(LiveUpdatePublisher::class, $live);
        $live->publish();
        $published = $this->runsChanged;
        $this->runsChanged = [];

        return $published;
    }

    /** @return list<string> the sorted project ids that the runs-changed updates since the last call name */
    private function publishedProjects(): array
    {
        $ids = [];
        foreach ($this->publishedRunsChanged() as $update) {
            preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $update->getTopics()[0], $match);
            $ids[] = $match[0] ?? '';
        }
        sort($ids);

        return $ids;
    }

    public function test_it_stores_an_account_reading(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-store@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0)]));

        self::assertSame(
            [['work', 'session', 29.0, '2026-10-07 17:00:00', false, 'stream', '2026-10-07 12:00:00']],
            array_map(
                static fn (array $row): array => [$row[0], $row[1], (float) $row[2], $row[3], $row[4], $row[5], $row[6]],
                $em->getConnection()->fetchAllNumeric('SELECT account, window_key, used_percent, resets_at, limited, source, read_at FROM bridge_account_readings WHERE bridge_id = :id', ['id' => (string) $bridgeId]),
            ),
        );
    }

    public function test_it_skips_a_reading_equal_to_the_latest_one(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-equal@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:00:00')]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:01:00')]));

        self::assertSame(1, $this->countReadings($em));
    }

    public function test_a_reading_equal_to_the_latest_one_moves_its_seen_time_and_keeps_its_read_time(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-seen@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:00:00')]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 14:00:00')]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 13:00:00')]));

        self::assertSame([['2026-10-07 12:00:00', '2026-10-07 14:00:00']], $this->readAndSeenTimes($em, $bridgeId));
    }

    public function test_a_window_repeated_in_one_heartbeat_moves_the_seen_time_of_the_new_reading(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-seen-new@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [
            self::reading('session', 29.0, readAt: '2026-10-07 12:00:00'),
            self::reading('session', 29.0, readAt: '2026-10-07 12:00:30'),
        ]));

        self::assertSame([['2026-10-07 12:00:00', '2026-10-07 12:00:30']], $this->readAndSeenTimes($em, $bridgeId));
    }

    /** @return list<list<mixed>> */
    private function readAndSeenTimes(\Doctrine\ORM\EntityManagerInterface $em, Uuid $bridgeId): array
    {
        return $em->getConnection()->fetchAllNumeric('SELECT read_at, seen_at FROM bridge_account_readings WHERE bridge_id = :id', ['id' => (string) $bridgeId]);
    }

    public function test_a_stored_reading_announces_the_followed_projects_after_the_commit(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-announce@example.com');
        $project = $this->project($em, $owner, 'Reading Announce');
        $bridgeId = Uuid::v4();
        $announced = $this->recordReadings();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [(string) $project->id], 'b4e39aa7', accountReadings: [self::reading('session', 29.0)]));

        self::assertSame([[(string) $project->id]], array_map(static fn (BridgeAccountReadingsStored $event): array => $event->projectIds, $announced->of(BridgeAccountReadingsStored::class)));
        self::assertSame(1, $this->countReadings($em), 'The event follows a committed row.');
    }

    public function test_a_heartbeat_that_stores_no_reading_announces_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-silent@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:00:00')]));
        $announced = $this->recordReadings();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: []));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0, readAt: '2026-10-07 12:01:00')]));

        self::assertSame([], $announced->of(BridgeAccountReadingsStored::class));
    }

    public function test_it_stores_a_reading_that_differs_in_use_reset_or_limit_and_compares_each_window_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-differs@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 29.0), self::reading('week', 29.0)]));
        self::assertSame(2, $this->countReadings($em));

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 30.0, readAt: '2026-10-07 12:01:00')]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 30.0, resetsAt: '2026-10-07 18:00:00', readAt: '2026-10-07 12:02:00')]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 30.0, resetsAt: '2026-10-07 18:00:00', limited: true, readAt: '2026-10-07 12:03:00')]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', null, resetsAt: '2026-10-07 18:00:00', limited: true, readAt: '2026-10-07 12:04:00')]));

        self::assertSame(6, $this->countReadings($em));
    }

    public function test_a_null_use_equals_a_null_use_and_differs_from_zero(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-null@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', null)]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', null, readAt: '2026-10-07 12:01:00')]));
        self::assertSame(1, $this->countReadings($em));

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', accountReadings: [self::reading('session', 0.0, readAt: '2026-10-07 12:02:00')]));
        self::assertSame(2, $this->countReadings($em));
    }

    public function test_a_window_repeated_in_one_heartbeat_stores_only_changes(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-reading-repeat@example.com');

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, Uuid::v4(), [], 'b4e39aa7', accountReadings: [
            self::reading('session', 29.0, readAt: '2026-10-07 12:00:00'),
            self::reading('session', 29.0, readAt: '2026-10-07 12:00:30'),
        ]));

        self::assertSame(1, $this->countReadings($em));
    }

    private static function reading(string $window, ?float $usedPercent, string $resetsAt = '2026-10-07 17:00:00', bool $limited = false, string $readAt = '2026-10-07 12:00:00'): AccountReadingReport
    {
        $utc = new \DateTimeZone('UTC');

        return new AccountReadingReport('work', $window, $usedPercent, new \DateTimeImmutable($resetsAt, $utc), $limited, 'stream', new \DateTimeImmutable($readAt, $utc));
    }

    private function countReadings(\Doctrine\ORM\EntityManagerInterface $em): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_account_readings');
    }

    private function handler(): RecordBridgeHeartbeatHandler
    {
        $handler = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $handler);

        return $handler;
    }
}
