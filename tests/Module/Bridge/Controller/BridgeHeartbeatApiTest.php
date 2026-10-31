<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Controller\Api\BridgeAccountInput;
use App\Module\Bridge\Controller\Api\BridgeCommandEntryInput;
use App\Module\Bridge\Controller\Api\BridgeCommandParamInput;
use App\Module\Bridge\Controller\Api\BridgeHookInput;
use App\Module\Bridge\Controller\Api\BridgeHostSampleInput;
use App\Module\Bridge\Controller\Api\BridgeRepositoryInput;
use App\Module\Bridge\Controller\Api\BridgeWorktreeInput;
use App\Module\Bridge\Controller\Api\RecordBridgeHeartbeatRequest;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\HostSampling;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class BridgeHeartbeatApiTest extends WebTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    private const string WORK_NOW = '2026-10-01 12:30:00';

    public function test_the_first_heartbeat_records_the_bridge_for_the_token_owner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-09-14 16:00:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-create@example.com');
        $project = $this->project($em, $owner, 'Heartbeat App');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"cliRange":"^1.0","paused":false,"commands":[],"workRequests":[],"lostClaims":[],"watchSessions":[]}', (string) $client->getResponse()->getContent());
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([(string) $project->id], $bridge->projects);
        self::assertNull($bridge->pausedReported);
        self::assertNull($bridge->capabilities);
        self::assertSame('b4e39aa7', $bridge->cliVersion);
        self::assertSame('2026-09-14 16:00:00', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
        self::assertNull($bridge->updateState);
        self::assertNull($bridge->updateVersion);
    }

    public function test_the_update_report_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'update' => ['state' => 'rolled-back', 'version' => '1.3.0', 'reason' => 'ignored'],
            'extra' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame(CliUpdateState::RolledBack, $bridge->updateState);
        self::assertSame('1.3.0', $bridge->updateVersion);
        self::assertNull($bridge->installMethod);
    }

    public function test_the_install_method_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-install@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'update' => ['state' => 'off', 'version' => '1.3.0', 'install' => 'homebrew'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(CliInstallMethod::Homebrew, $this->bridge($owner, $bridgeId)->installMethod);
    }

    /** A newer CLI may name a method this server does not know, and its heartbeat must still land. */
    public function test_an_unknown_install_method_is_stored_as_null(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-install-unknown@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'update' => ['state' => 'current', 'install' => 'snap'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame(CliUpdateState::Current, $bridge->updateState);
        self::assertNull($bridge->installMethod);
    }

    /** The row holds current state, so a heartbeat with no update report clears the last one. */
    public function test_a_heartbeat_without_an_update_report_clears_the_stored_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update-clear@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '1.2.0', 'update' => ['state' => 'updating', 'version' => '1.3.0']]);
        self::assertSame(CliUpdateState::Updating, $this->bridge($owner, $bridgeId)->updateState);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '1.3.0']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertNull($bridge->updateState);
        self::assertNull($bridge->updateVersion);
    }

    /** The row holds current state, so a later heartbeat replaces every field and writes no second row. */
    public function test_a_later_heartbeat_replaces_the_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-09-14 16:01:30'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-replace@example.com');
        $first = $this->project($em, $owner, 'First Heartbeat');
        $second = $this->project($em, $owner, 'Second Heartbeat');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $this->seedBridge($em, $owner, Uuid::fromString($bridgeId), [(string) $first->id], 'old', new \DateTimeImmutable('2026-09-14 16:00:30'));

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) $second->id],
            'cliVersion' => 'new (dirty)',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->countBridges());
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([(string) $second->id], $bridge->projects);
        self::assertSame('new (dirty)', $bridge->cliVersion);
        self::assertSame('2026-09-14 16:01:30', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
    }

    /**
     * Two accounts that share one config directory send one bridge id, and
     * each keeps a row of its own. Neither heartbeat touches the other's row.
     */
    public function test_a_second_account_with_the_same_bridge_id_keeps_its_own_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $first = $this->user($em, 'heartbeat-first-account@example.com');
        $second = $this->user($em, 'heartbeat-second-account@example.com');
        $secondProject = $this->project($em, $second, 'Second Account Heartbeat');
        $raw = $this->agentToken($client, $second);
        $bridgeId = Uuid::v4();
        $seenAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->seedBridge($em, $first, $bridgeId, [], 'first', $seenAt);

        $this->put($client, (string) $bridgeId, $raw, [
            'projects' => [(string) $secondProject->id],
            'cliVersion' => 'second',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->countBridges());
        $secondRow = $this->bridge($second, (string) $bridgeId);
        self::assertSame([(string) $secondProject->id], $secondRow->projects);
        self::assertSame('second', $secondRow->cliVersion);
        $firstRow = $this->bridge($first, (string) $bridgeId);
        self::assertSame([], $firstRow->projects);
        self::assertSame('first', $firstRow->cliVersion);
        self::assertEquals($seenAt, $firstRow->lastSeenAt);
    }

    /**
     * A project deleted while the bridge runs stays in its list until a restart,
     * so refusing the whole heartbeat would make a live bridge read as quiet.
     */
    public function test_a_project_the_caller_does_not_own_is_left_out_of_the_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-filter@example.com');
        $stranger = $this->user($em, 'heartbeat-stranger@example.com');
        $own = $this->project($em, $owner, 'Own Heartbeat');
        $foreign = $this->project($em, $stranger, 'Foreign Heartbeat');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) Uuid::v7(), (string) $own->id, (string) $foreign->id, strtoupper((string) $own->id)],
            'cliVersion' => 'b4e39aa7',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $own->id], $this->bridge($owner, $bridgeId)->projects);
    }

    public function test_a_bridge_that_follows_no_project_is_recorded_with_an_empty_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-empty@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->bridge($owner, $bridgeId)->projects);
    }

    public function test_the_hook_report_is_stored_with_each_time_in_one_format(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'hooks' => [
            self::hook(),
            self::hook(['event' => 'stop', 'lastRunAt' => null, 'outcome' => 'never']),
            self::hook(['event' => 'busy', 'lastRunAt' => '2026-09-14T18:00:00+02:00', 'outcome' => 'timeout', 'error' => '  ']),
            self::hook(['event' => 'idle', 'lastRunAt' => '2026-09-14t16:00:00z', 'outcome' => 'failed', 'error' => 'exit 1']),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'start', 'lastRunAt' => '2026-09-14T16:00:00+00:00', 'outcome' => 'ok', 'error' => null],
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'stop', 'lastRunAt' => null, 'outcome' => 'never', 'error' => null],
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'busy', 'lastRunAt' => '2026-09-14T18:00:00+02:00', 'outcome' => 'timeout', 'error' => null],
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'idle', 'lastRunAt' => '2026-09-14T16:00:00+00:00', 'outcome' => 'failed', 'error' => 'exit 1'],
        ], $this->bridge($owner, $bridgeId)->hooks);
    }

    /** A bridge from before hooks sends no report, so its heartbeat leaves the stored rows alone. */
    public function test_a_heartbeat_without_hooks_keeps_the_stored_report(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'hooks' => [self::hook()]]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->bridge($owner, $bridgeId)->hooks);
    }

    public function test_the_worker_pools_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workerPools' => [
            self::pool(),
            self::pool(['name' => 'quick-2', 'size' => 1, 'inUse' => 1, 'queued' => 1000]),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['name' => 'default', 'size' => 3, 'inUse' => 2, 'queued' => 5],
            ['name' => 'quick-2', 'size' => 1, 'inUse' => 1, 'queued' => 1000],
        ], $this->bridge($owner, $bridgeId)->workerPools);
    }

    /** A bridge from before worker pools sends none, so its heartbeat leaves the stored rows alone. */
    public function test_a_heartbeat_without_worker_pools_keeps_the_stored_rows(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workerPools' => [self::pool()]]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workerPools' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->bridge($owner, $bridgeId)->workerPools ?? []);
    }

    public function test_the_accounts_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [
            self::account(),
            self::account(['name' => 'personal', 'state' => 'failing', 'reason' => '  claude is not logged in  ']),
            self::account(['name' => 'spare', 'state' => 'failing', 'reason' => ' ']),
        ]]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([
            ['name' => 'work', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'used' => true],
            ['name' => 'personal', 'harness' => 'claude-code', 'state' => 'failing', 'reason' => 'claude is not logged in', 'used' => true],
            ['name' => 'spare', 'harness' => 'claude-code', 'state' => 'failing', 'reason' => null, 'used' => true],
        ], $bridge->accounts);
        self::assertNotNull($bridge->accountsReportedAt);
    }

    /** A reason belongs to a failing account alone, so a ready account drops it rather than failing the heartbeat. */
    public function test_a_ready_account_drops_its_reason(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-ready-reason@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [self::account(['reason' => 'stale text'])]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['name' => 'work', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'used' => true]], $this->bridge($owner, $bridgeId)->accounts);
    }

    /** The CLI sends used on every account. A false value is stored, and a missing value reads as true. */
    public function test_the_used_flag_is_stored_and_defaults_to_true(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-used@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [
            self::account(['name' => 'idle', 'used' => false]),
            self::account(['name' => 'busy', 'used' => true]),
            self::account(['name' => 'legacy']),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([false, true, true], array_column($this->bridge($owner, $bridgeId)->accounts ?? [], 'used'));
    }

    public function test_a_used_flag_that_is_not_a_boolean_is_rejected(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-used-bad@example.com');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [self::account(['used' => 'maybe'])]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_command_entries_and_worktrees_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-entries@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $projectId = strtoupper((string) Uuid::v7());

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'commandEntries' => [
            self::commandEntry(),
            self::commandEntry(['name' => 'plain', 'description' => '  ', 'params' => [], 'run' => ['true']]),
        ], 'worktrees' => [['projectId' => $projectId, 'branch' => 'epic/42', 'path' => '/work/card-42']]]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([
            [
                'name' => 'design-export',
                'description' => 'Export the design system',
                'params' => [['name' => 'out', 'pattern' => '^var/[a-z-]+$', 'description' => null]],
                'run' => ['bin/console', 'app:design-system:export', '{out}'],
                'timeout' => '10m',
            ],
            ['name' => 'plain', 'description' => null, 'params' => [], 'run' => ['true'], 'timeout' => '10m'],
        ], $bridge->commandEntries);
        self::assertSame([['projectId' => strtolower($projectId), 'branch' => 'epic/42', 'path' => '/work/card-42']], $bridge->worktrees);
        self::assertNotNull($bridge->commandEntriesReportedAt);
        self::assertNotNull($bridge->worktreesReportedAt);
    }

    /** A bridge from before command entries sends none, so a missing key keeps the rows and an empty list clears them. */
    public function test_a_missing_key_keeps_the_entries_and_an_empty_list_clears_them(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-entries-keep@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $worktree = ['projectId' => (string) Uuid::v7(), 'branch' => 'main', 'path' => '/work/main'];

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'commandEntries' => [self::commandEntry()], 'worktrees' => [$worktree]]);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'commandEntries' => null, 'worktrees' => null]);

        $bridge = $this->bridge($owner, $bridgeId);
        self::assertCount(1, $bridge->commandEntries ?? []);
        self::assertSame([$worktree], $bridge->worktrees);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'commandEntries' => [], 'worktrees' => []]);

        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([], $bridge->commandEntries);
        self::assertSame([], $bridge->worktrees);
    }

    public function test_the_repositories_are_stored_kept_and_cleared(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-repositories@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $projectId = strtoupper((string) Uuid::v7());
        $base = ['projects' => [], 'cliVersion' => 'b4e39aa7'];

        $this->put($client, $bridgeId, $raw, $base + ['repositories' => [['projectId' => $projectId, 'path' => '/work/loupe', 'remoteUrl' => 'git@github.com:acme/loupe.git'], ['projectId' => $projectId, 'path' => '/work/other']]]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([
            ['projectId' => strtolower($projectId), 'path' => '/work/loupe', 'remoteUrl' => 'git@github.com:acme/loupe.git'],
            ['projectId' => strtolower($projectId), 'path' => '/work/other', 'remoteUrl' => ''],
        ], $bridge->repositories);
        self::assertNotNull($bridge->repositoriesReportedAt);

        $this->put($client, $bridgeId, $raw, $base);
        self::assertCount(2, $this->bridge($owner, $bridgeId)->repositories ?? []);

        $this->put($client, $bridgeId, $raw, $base + ['repositories' => []]);
        self::assertSame([], $this->bridge($owner, $bridgeId)->repositories);
    }

    /** A bridge from before accounts sends none, so its heartbeat leaves the stored rows alone. */
    public function test_a_heartbeat_without_accounts_keeps_the_stored_rows(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-accounts-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [self::account()]]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->bridge($owner, $bridgeId)->accounts ?? []);
    }

    public function test_the_pause_state_and_the_capabilities_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-capabilities@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'paused' => true, 'capabilities' => ['commands', 'pause']]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertTrue($bridge->pausedReported);
        self::assertSame(['commands', 'pause'], $bridge->capabilities);
        self::assertTrue($bridge->takesCommands());
    }

    public function test_the_work_kinds_are_stored_once_each_and_replaced_by_an_empty_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-work-kinds@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workKinds' => ['fix', 'tech-design', 'fix', 'analysis:cost-report']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['fix', 'tech-design', 'analysis:cost-report'], $this->bridge($owner, $bridgeId)->workKinds);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workKinds' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->bridge($owner, $bridgeId)->workKinds);
    }

    /** A bridge from before work kinds sends no field, so it stores an empty list and counts as able to run no kind. */
    public function test_a_heartbeat_without_work_kinds_stores_an_empty_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-work-kinds-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->bridge($owner, $bridgeId)->workKinds);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workKinds' => ['fix']]);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workKinds' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->bridge($owner, $bridgeId)->workKinds);
    }

    /** A bridge from before commands sends neither field, so its heartbeat leaves the stored values alone. */
    public function test_a_heartbeat_without_the_pause_state_or_the_capabilities_keeps_the_stored_values(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-capabilities-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'paused' => false, 'capabilities' => ['commands']]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'paused' => null, 'capabilities' => null]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertFalse($bridge->pausedReported);
        self::assertSame(['commands'], $bridge->capabilities);
    }

    /**
     * The reply carries the pause a person asked for, and the commands this
     * bridge of this owner has still to act on, oldest first.
     */
    public function test_the_reply_carries_the_pause_request_and_the_pending_commands(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-09-29 12:10:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-commands@example.com');
        $stranger = $this->user($em, 'heartbeat-commands-stranger@example.com');
        $project = $this->project($em, $owner, 'Heartbeat Commands');
        $foreign = $this->project($em, $stranger, 'Heartbeat Commands Foreign');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $owner, $bridgeId);
        $bridge->pauseRequested = true;
        $em->flush();

        $later = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId, runKey: Uuid::v4()), requestedAt: new \DateTimeImmutable('2026-09-29 12:05:00'));
        $earlier = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: new \DateTimeImmutable('2026-09-29 12:00:00'), kind: BridgeCommandKind::ResumeRun);
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: new \DateTimeImmutable('2026-09-29 11:00:00'));
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), state: BridgeCommandState::Done);
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: Uuid::v4()));
        $this->seedCommand($em, $this->seedRun($em, $foreign, bridgeId: $bridgeId));

        $this->put($client, (string) $bridgeId, $raw, ['projects' => [(string) $project->id], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        $reply = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reply);
        self::assertTrue($reply['paused']);
        self::assertSame([(string) $earlier->id, (string) $later->id], array_column($reply['commands'], 'commandId'));
        self::assertSame(BridgeCommandPayload::of($later), $reply['commands'][1]);
        self::assertSame('resume-run', $reply['commands'][0]['kind']);
    }

    public function test_a_bridge_that_takes_work_requests_gets_the_open_requests_of_the_projects_it_follows(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers@example.com');
        $stranger = $this->user($em, 'heartbeat-offers-stranger@example.com');
        $followed = $this->project($em, $owner, 'Offers Followed');
        $unfollowed = $this->project($em, $owner, 'Offers Unfollowed');
        $foreign = $this->project($em, $stranger, 'Offers Foreign');
        $later = $this->seedWorkRequest($em, $followed, createdAt: new \DateTimeImmutable('2026-10-01 12:10:00'));
        $earlier = $this->seedWorkRequest($em, $followed, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Done);
        $this->seedWorkRequest($em, $unfollowed);
        $this->seedWorkRequest($em, $foreign);

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $followed->id, (string) $foreign->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame([(string) $earlier->id, (string) $later->id], array_column($reply['workRequests'], 'workRequestId'));
        self::assertSame(WorkRequestPayload::of($earlier), $reply['workRequests'][0]);
        self::assertSame([], $reply['lostClaims']);
    }

    public function test_a_bridge_gets_no_request_of_a_project_that_holds_its_new_work_whatever_its_subject(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers-held@example.com');
        $held = $this->project($em, $owner, 'Offers Held');
        $free = $this->project($em, $owner, 'Offers Free');
        $this->repositoryWithGaps($held);
        $this->repositoryWithGaps($free, []);
        $this->seedWorkRequest($em, $held);
        $this->seedWorkRequest($em, $held, subject: new WorkSubject('analysis', Uuid::v7()));
        $offered = $this->seedWorkRequest($em, $free);

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $held->id, (string) $free->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $offered->id], array_column($this->reply($client)['workRequests'], 'workRequestId'));
    }

    public function test_a_request_that_needs_a_capability_reaches_only_a_bridge_that_reports_it(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers-capability@example.com');
        $project = $this->project($em, $owner, 'Offers Capability');
        $any = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $interactive = $this->seedWorkRequest($em, $project, kind: 'design', capability: Bridge::CAPABILITY_INTERACTIVE, createdAt: new \DateTimeImmutable('2026-10-01 12:05:00'));
        $raw = $this->agentToken($client, $owner);

        $this->put($client, (string) Uuid::v4(), $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);
        self::assertSame([(string) $any->id], array_column($this->reply($client)['workRequests'], 'workRequestId'));

        $this->put($client, (string) Uuid::v4(), $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_INTERACTIVE],
        ]);
        self::assertSame([(string) $any->id, (string) $interactive->id], array_column($this->reply($client)['workRequests'], 'workRequestId'));
    }

    public function test_a_request_for_one_bridge_is_offered_to_that_bridge_alone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers-target@example.com');
        $project = $this->project($em, $owner, 'Offers Target');
        $target = Uuid::v4();
        $any = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $targeted = $this->seedWorkRequest($em, $project, kind: 'design-export', capability: Bridge::CAPABILITY_RUN_COMMAND, createdAt: new \DateTimeImmutable('2026-10-01 12:05:00'), targetBridgeId: $target, branch: 'main', params: ['out' => 'var/x']);
        $raw = $this->agentToken($client, $owner);
        $body = [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND],
        ];

        $this->put($client, (string) Uuid::v4(), $raw, $body);
        self::assertSame([(string) $any->id], array_column($this->reply($client)['workRequests'], 'workRequestId'));

        $this->put($client, (string) $target, $raw, $body);
        $offers = $this->reply($client)['workRequests'];
        self::assertSame([(string) $any->id, (string) $targeted->id], array_column($offers, 'workRequestId'));
        self::assertSame([(string) $target, 'main', ['out' => 'var/x']], [$offers[1]['targetBridgeId'], $offers[1]['branch'], $offers[1]['params']]);
    }

    public function test_a_bridge_that_takes_no_work_requests_gets_no_offer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers-none@example.com');
        $project = $this->project($em, $owner, 'Offers None');
        $this->seedWorkRequest($em, $project);

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => ['commands', Bridge::CAPABILITY_INTERACTIVE],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame([], $reply['workRequests']);
        self::assertSame([], $reply['lostClaims']);
    }

    public function test_a_named_claim_that_the_bridge_holds_renews_its_lease(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-renew@example.com');
        $project = $this->project($em, $owner, 'Renew');
        $bridgeId = Uuid::v4();
        $token = Uuid::v4();
        $held = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: $token, leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));

        $this->put($client, (string) $bridgeId, $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
            'workClaims' => [
                ['id' => (string) $held->id, 'claimToken' => (string) $token],
                ['id' => strtoupper((string) $held->id), 'claimToken' => (string) $token],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame([], $reply['lostClaims']);
        self::assertSame([], $reply['workRequests']);
        self::assertSame('2026-10-01 13:00:00', $this->leaseUntil($held));
    }

    public function test_a_named_claim_that_the_bridge_no_longer_holds_is_lost(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-lost@example.com');
        $project = $this->project($em, $owner, 'Lost');
        $bridgeId = Uuid::v4();
        $token = Uuid::v4();
        $lease = new \DateTimeImmutable('2026-10-01 12:31:00');
        $held = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: $token, leaseUntil: $lease);
        $otherToken = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: Uuid::v4(), leaseUntil: $lease);
        $reopened = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:20:00'));
        $withdrawn = $this->seedWorkRequest($em, $project, state: WorkRequestState::Cancelled, bridgeId: $bridgeId, claimToken: $token, leaseUntil: $lease);
        $unknown = Uuid::v4();

        $this->put($client, (string) $bridgeId, $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'workClaims' => [
                ['id' => (string) $otherToken->id, 'claimToken' => (string) $token],
                ['id' => (string) $held->id, 'claimToken' => (string) $token],
                ['id' => (string) $reopened->id, 'claimToken' => (string) $token],
                ['id' => (string) $withdrawn->id, 'claimToken' => (string) $token],
                ['id' => (string) $unknown, 'claimToken' => (string) $token],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame(
            [(string) $otherToken->id, (string) $reopened->id, (string) $withdrawn->id, (string) $unknown],
            $reply['lostClaims'],
        );
        self::assertSame([], $reply['workRequests']);
        self::assertSame('2026-10-01 13:00:00', $this->leaseUntil($held));
        self::assertSame('2026-10-01 12:31:00', $this->leaseUntil($otherToken));
    }

    public function test_a_claim_of_another_bridge_is_lost_and_keeps_its_lease(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-lost-bridge@example.com');
        $project = $this->project($em, $owner, 'Lost Bridge');
        $token = Uuid::v4();
        $other = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: $token, leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'workClaims' => [['id' => (string) $other->id, 'claimToken' => (string) $token]],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $other->id], $this->reply($client)['lostClaims']);
        self::assertSame('2026-10-01 12:31:00', $this->leaseUntil($other));
    }

    /** A bridge that predates work requests sends no list, and its claims, if any, run out. */
    public function test_a_heartbeat_without_work_claims_renews_no_lease(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-no-claims@example.com');
        $project = $this->project($em, $owner, 'No Claims');
        $bridgeId = Uuid::v4();
        $held = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));

        $this->put($client, (string) $bridgeId, $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->reply($client)['lostClaims']);
        self::assertSame('2026-10-01 12:31:00', $this->leaseUntil($held));
    }

    public function test_the_host_samples_are_stored_while_sampling_is_on(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-10-07 12:02:00'));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-on@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'hostSamples' => [
                self::sample(),
                self::sample(['sampledAt' => '2026-10-07T14:01:00+02:00', 'cpuPct' => [0, 100], 'batteryPct' => null, 'onAc' => null]),
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            [
                'bridge_id' => $bridgeId,
                'sampled_at' => '2026-10-07 12:00:00',
                'cpu_pct' => '[12.5, 40.0]',
                'mem_used' => 8_000_000_000,
                'mem_total' => 17_179_869_184,
                'swap_used' => 1_000_000,
                'battery_pct' => 80.0,
                'on_ac' => false,
            ],
            [
                'bridge_id' => $bridgeId,
                'sampled_at' => '2026-10-07 12:01:00',
                'cpu_pct' => '[0.0, 100.0]',
                'mem_used' => 8_000_000_000,
                'mem_total' => 17_179_869_184,
                'swap_used' => 1_000_000,
                'battery_pct' => null,
                'on_ac' => null,
            ],
        ], $this->samples());
    }

    /** A bridge that still holds the old flag value until it reconnects must not fail its heartbeat. */
    public function test_the_host_samples_are_dropped_while_sampling_is_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->storeHostSampling('false');
        $owner = $this->user($em, 'heartbeat-samples-off@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '1.2.0', 'hostSamples' => [self::sample()]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('1.2.0', $this->bridge($owner, $bridgeId)->cliVersion);
        self::assertSame([], $this->samples());
    }

    public function test_a_repeated_sample_is_stored_once(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-10-07 12:02:00'));
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-repeat@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $payload = ['projects' => [], 'cliVersion' => '1.2.0', 'hostSamples' => [self::sample(), self::sample(['cpuPct' => [99]])]];

        $this->put($client, $bridgeId, $raw, $payload);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, $payload);
        self::assertResponseStatusCodeSame(200);

        $samples = $this->samples();
        self::assertCount(1, $samples);
        self::assertSame('[12.5, 40.0]', $samples[0]['cpu_pct']);
    }

    public function test_the_kinds_the_stop_value_and_the_readings_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-account-usage@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [
            self::account(['kinds' => ['fix', 'fix', 'analysis:cost-report'], 'stopAt' => 90, 'readings' => [
                self::accountReading(),
                self::accountReading(['window' => 'week', 'usedPercent' => null, 'limited' => true]),
            ]]),
            self::account(['name' => 'spare']),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['name' => 'work', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'used' => true, 'kinds' => ['fix', 'analysis:cost-report'], 'stopAt' => 90],
            ['name' => 'spare', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'used' => true],
        ], $this->bridge($owner, $bridgeId)->accounts);
        $rows = $em->getConnection()->fetchAllAssociative('SELECT account, window_key, used_percent, limited, source FROM bridge_account_readings ORDER BY window_key');
        self::assertSame([['work', 'session', 29.5, false, 'stream'], ['work', 'week', null, true, 'stream']], array_map(
            static fn (array $row): array => [$row['account'], $row['window_key'], null === $row['used_percent'] ? null : (float) $row['used_percent'], $row['limited'], $row['source']],
            $rows,
        ));
    }

    public function test_a_reading_time_with_an_offset_is_stored_in_utc(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-reading-offset@example.com');
        $raw = $this->agentToken($client, $owner);
        $reset = new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC'));

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [
            self::account(['readings' => [self::accountReading(['resetsAt' => $reset->setTimezone(new \DateTimeZone('+02:00'))->format(\DateTimeInterface::RFC3339)])]]),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($reset->format('Y-m-d H:i:s'), $this->em()->getConnection()->fetchOne('SELECT resets_at FROM bridge_account_readings'));
    }

    public function test_the_edge_values_of_a_reading_are_accepted(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-reading-edges@example.com');
        $raw = $this->agentToken($client, $owner);
        $readings = array_map(
            static fn (int $index): array => self::accountReading(['window' => 'w'.$index, 'usedPercent' => 0 === $index ? 0 : 200]),
            range(0, BridgeAccountInput::MAX_READINGS - 1),
        );

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'accounts' => [
            self::account(['stopAt' => 1, 'readings' => $readings]),
            self::account(['name' => 'spare', 'stopAt' => 100, 'readings' => [self::accountReading(['window' => str_repeat('a', 16)])]]),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(BridgeAccountInput::MAX_READINGS + 1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_account_readings'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function accountReading(array $overrides = []): array
    {
        return array_merge([
            'window' => 'session',
            'usedPercent' => 29.5,
            'resetsAt' => new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339),
            'limited' => false,
            'source' => 'stream',
            'readAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339),
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function workClaim(array $overrides = []): array
    {
        return array_merge(['id' => (string) Uuid::v4(), 'claimToken' => (string) Uuid::v4()], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function account(array $overrides = []): array
    {
        return array_merge(['name' => 'work', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function commandEntry(array $overrides = []): array
    {
        return array_merge([
            'name' => 'design-export',
            'description' => 'Export the design system',
            'params' => [['name' => 'out', 'pattern' => '^var/[a-z-]+$', 'description' => '']],
            'run' => ['bin/console', 'app:design-system:export', '{out}'],
            'timeout' => '10m',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function repository(array $overrides = []): array
    {
        return array_merge(['projectId' => (string) Uuid::v7(), 'path' => '/work/main', 'remoteUrl' => 'git@github.com:acme/loupe.git'], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function worktree(array $overrides = []): array
    {
        return array_merge(['projectId' => (string) Uuid::v7(), 'branch' => 'main', 'path' => '/work/main'], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function pool(array $overrides = []): array
    {
        return array_merge(['name' => 'default', 'size' => 3, 'inUse' => 2, 'queued' => 5], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function hook(array $overrides = []): array
    {
        return array_merge([
            'package' => 'github:acme/loupe-hooks',
            'ref' => 'v1.2.0',
            'event' => 'start',
            'lastRunAt' => '2026-09-14T16:00:00.000Z',
            'outcome' => 'ok',
            'error' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function sample(array $overrides = []): array
    {
        return array_merge([
            'sampledAt' => '2026-10-07T12:00:00Z',
            'cpuPct' => [12.5, 40],
            'memUsed' => 8_000_000_000,
            'memTotal' => 17_179_869_184,
            'swapUsed' => 1_000_000,
            'batteryPct' => 80,
            'onAc' => false,
        ], $overrides);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'hooks that are not a list' => [['hooks' => 'loupe']];
        yield 'hooks keyed by name' => [['hooks' => ['start' => self::hook()]]];
        yield 'a hook that is not an object' => [['hooks' => ['loupe']]];
        yield 'too many hooks' => [['hooks' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_HOOKS + 1, self::hook())]];
        yield 'a hook with no package' => [['hooks' => [self::hook(['package' => null])]]];
        yield 'a hook package that is too long' => [['hooks' => [self::hook(['package' => str_repeat('a', BridgeHookInput::MAX_PACKAGE_LENGTH + 1)])]]];
        yield 'a hook with a blank ref' => [['hooks' => [self::hook(['ref' => ' '])]]];
        yield 'a hook ref that is too long' => [['hooks' => [self::hook(['ref' => str_repeat('a', BridgeHookInput::MAX_REF_LENGTH + 1)])]]];
        yield 'an unknown hook event' => [['hooks' => [self::hook(['event' => 'deploy'])]]];
        yield 'an unknown hook outcome' => [['hooks' => [self::hook(['outcome' => 'crashed'])]]];
        yield 'a hook with no outcome' => [['hooks' => [self::hook(['outcome' => null])]]];
        yield 'a hook run time that is not a date' => [['hooks' => [self::hook(['lastRunAt' => 'yesterday-ish'])]]];
        yield 'a hook run time in words' => [['hooks' => [self::hook(['lastRunAt' => 'tomorrow'])]]];
        yield 'a hook run time on a day the month lacks' => [['hooks' => [self::hook(['lastRunAt' => '2026-02-31T16:00:00Z'])]]];
        yield 'a hook run time with no offset' => [['hooks' => [self::hook(['lastRunAt' => '2026-09-14T16:00:00'])]]];
        yield 'a hook error that is too long' => [['hooks' => [self::hook(['error' => str_repeat('a', BridgeHookInput::MAX_ERROR_LENGTH + 1)])]]];
        yield 'worker pools that are not a list' => [['workerPools' => 'default']];
        yield 'worker pools keyed by name' => [['workerPools' => ['default' => self::pool()]]];
        yield 'too many worker pools' => [['workerPools' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_WORKER_POOLS + 1, self::pool())]];
        yield 'a worker pool with a bad name' => [['workerPools' => [self::pool(['name' => 'Quick Pool'])]]];
        yield 'a worker pool with no name' => [['workerPools' => [self::pool(['name' => null])]]];
        yield 'a worker pool name with a trailing newline' => [['workerPools' => [self::pool(['name' => "default\n"])]]];
        yield 'a worker pool with no size' => [['workerPools' => [self::pool(['size' => null])]]];
        yield 'a worker pool with a negative use' => [['workerPools' => [self::pool(['inUse' => -1])]]];
        yield 'a worker pool with a queue above the limit' => [['workerPools' => [self::pool(['queued' => 1001])]]];
        yield 'a worker pool size above the limit' => [['workerPools' => [self::pool(['size' => 1001])]]];
        yield 'a worker pool size as text' => [['workerPools' => [self::pool(['size' => 'three'])]]];
        yield 'accounts that are not a list' => [['accounts' => 'work']];
        yield 'accounts keyed by name' => [['accounts' => ['work' => self::account()]]];
        yield 'an account that is not an object' => [['accounts' => ['work']]];
        yield 'too many accounts' => [['accounts' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_ACCOUNTS + 1, self::account())]];
        yield 'an account with a bad name' => [['accounts' => [self::account(['name' => 'Work Account'])]]];
        yield 'an account with no name' => [['accounts' => [self::account(['name' => null])]]];
        yield 'an account name with a trailing newline' => [['accounts' => [self::account(['name' => "work\n"])]]];
        yield 'a kind list that is not a list' => [['accounts' => [self::account(['kinds' => 'fix'])]]];
        yield 'a kind with a bad name' => [['accounts' => [self::account(['kinds' => ['Fix Card']])]]];
        yield 'too many kinds' => [['accounts' => [self::account(['kinds' => array_map(static fn (int $i): string => 'kind'.$i, range(0, RecordBridgeHeartbeatRequest::MAX_WORK_KINDS))])]]];
        yield 'a stop value of zero' => [['accounts' => [self::account(['stopAt' => 0])]]];
        yield 'a stop value above 100' => [['accounts' => [self::account(['stopAt' => 101])]]];
        yield 'a stop value that is not a number' => [['accounts' => [self::account(['stopAt' => 'high'])]]];
        yield 'readings that are not a list' => [['accounts' => [self::account(['readings' => self::accountReading()])]]];
        yield 'too many windows for one account' => [['accounts' => [self::account(['readings' => array_map(static fn (int $i): array => self::accountReading(['window' => 'w'.$i]), range(0, BridgeAccountInput::MAX_READINGS))])]]];
        yield 'a use below zero' => [['accounts' => [self::account(['readings' => [self::accountReading(['usedPercent' => -0.1])]])]]];
        yield 'a use above 200' => [['accounts' => [self::account(['readings' => [self::accountReading(['usedPercent' => 200.1])]])]]];
        yield 'a use that is not a number' => [['accounts' => [self::account(['readings' => [self::accountReading(['usedPercent' => 'full'])]])]]];
        yield 'a window in upper case' => [['accounts' => [self::account(['readings' => [self::accountReading(['window' => 'Session'])]])]]];
        yield 'a window with a hyphen' => [['accounts' => [self::account(['readings' => [self::accountReading(['window' => 'five-hour'])]])]]];
        yield 'a window that is too long' => [['accounts' => [self::account(['readings' => [self::accountReading(['window' => str_repeat('a', 17)])]])]]];
        yield 'a window with a trailing newline' => [['accounts' => [self::account(['readings' => [self::accountReading(['window' => "week\n"])]])]]];
        yield 'a blank window' => [['accounts' => [self::account(['readings' => [self::accountReading(['window' => ''])]])]]];
        yield 'a reset more than 31 days ahead' => [['accounts' => [self::account(['readings' => [self::accountReading(['resetsAt' => new \DateTimeImmutable('+32 days', new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339)])]])]]];
        yield 'a reset that is not a date' => [['accounts' => [self::account(['readings' => [self::accountReading(['resetsAt' => 'soon'])]])]]];
        yield 'a reading with no reset' => [['accounts' => [self::account(['readings' => [self::accountReading(['resetsAt' => null])]])]]];
        yield 'a reading with no limited value' => [['accounts' => [self::account(['readings' => [self::accountReading(['limited' => null])]])]]];
        yield 'a reading with no read time' => [['accounts' => [self::account(['readings' => [self::accountReading(['readAt' => null])]])]]];
        yield 'a reading with a blank source' => [['accounts' => [self::account(['readings' => [self::accountReading(['source' => ' '])]])]]];
        yield 'a reading source that is too long' => [['accounts' => [self::account(['readings' => [self::accountReading(['source' => str_repeat('a', 41)])]])]]];
        yield 'an account name that is too long' => [['accounts' => [self::account(['name' => str_repeat('a', 41)])]]];
        yield 'an account with a bad harness' => [['accounts' => [self::account(['harness' => 'Claude Code'])]]];
        yield 'an account with no harness' => [['accounts' => [self::account(['harness' => null])]]];
        yield 'an account with an unknown state' => [['accounts' => [self::account(['state' => 'broken'])]]];
        yield 'an account with no state' => [['accounts' => [self::account(['state' => null])]]];
        yield 'an account reason that is too long' => [['accounts' => [self::account(['state' => 'failing', 'reason' => str_repeat('a', BridgeAccountInput::MAX_REASON_LENGTH + 1)])]]];
        yield 'an account reason that is not text' => [['accounts' => [self::account(['state' => 'failing', 'reason' => 7])]]];
        yield 'command entries that are not a list' => [['commandEntries' => 'design-export']];
        yield 'command entries keyed by name' => [['commandEntries' => ['design-export' => self::commandEntry()]]];
        yield 'too many command entries' => [['commandEntries' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_COMMAND_ENTRIES + 1, self::commandEntry())]];
        yield 'a command entry that is not an object' => [['commandEntries' => ['design-export']]];
        yield 'a command entry with no name' => [['commandEntries' => [self::commandEntry(['name' => null])]]];
        yield 'a command entry name that is too long' => [['commandEntries' => [self::commandEntry(['name' => str_repeat('a', BridgeCommandEntryInput::MAX_NAME_LENGTH + 1)])]]];
        yield 'a command entry description that is too long' => [['commandEntries' => [self::commandEntry(['description' => str_repeat('a', BridgeCommandEntryInput::MAX_DESCRIPTION_LENGTH + 1)])]]];
        yield 'a command entry with no timeout' => [['commandEntries' => [self::commandEntry(['timeout' => null])]]];
        yield 'a command entry with an empty run' => [['commandEntries' => [self::commandEntry(['run' => []])]]];
        yield 'a command entry with no run' => [['commandEntries' => [self::commandEntry(['run' => null])]]];
        yield 'a command entry run element that is not text' => [['commandEntries' => [self::commandEntry(['run' => ['bin/console', 7]])]]];
        yield 'a command entry run element that is too long' => [['commandEntries' => [self::commandEntry(['run' => [str_repeat('a', BridgeCommandEntryInput::MAX_RUN_ELEMENT_LENGTH + 1)]])]]];
        yield 'a command entry with too many run elements' => [['commandEntries' => [self::commandEntry(['run' => array_fill(0, BridgeCommandEntryInput::MAX_RUN_ELEMENTS + 1, 'a')])]]];
        yield 'a command entry with params keyed by name' => [['commandEntries' => [self::commandEntry(['params' => ['out' => ['name' => 'out', 'pattern' => 'a']]])]]];
        yield 'a command entry with too many params' => [['commandEntries' => [self::commandEntry(['params' => array_fill(0, BridgeCommandEntryInput::MAX_PARAMS + 1, ['name' => 'out', 'pattern' => 'a'])])]]];
        yield 'a command param with no name' => [['commandEntries' => [self::commandEntry(['params' => [['name' => null, 'pattern' => 'a']]])]]];
        yield 'a command param with no pattern' => [['commandEntries' => [self::commandEntry(['params' => [['name' => 'out', 'pattern' => '']]])]]];
        yield 'a command param pattern that is too long' => [['commandEntries' => [self::commandEntry(['params' => [['name' => 'out', 'pattern' => str_repeat('a', BridgeCommandParamInput::MAX_PATTERN_LENGTH + 1)]]])]]];
        yield 'repositories that are not a list' => [['repositories' => 'main']];
        yield 'too many repositories' => [['repositories' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_REPOSITORIES + 1, self::repository())]];
        yield 'a repository with a project id that is not a uuid' => [['repositories' => [self::repository(['projectId' => 'loupe'])]]];
        yield 'a repository with no path' => [['repositories' => [self::repository(['path' => ''])]]];
        yield 'a repository path that is too long' => [['repositories' => [self::repository(['path' => str_repeat('a', BridgeRepositoryInput::MAX_PATH_LENGTH + 1)])]]];
        yield 'a repository remote that is too long' => [['repositories' => [self::repository(['remoteUrl' => str_repeat('a', BridgeRepositoryInput::MAX_REMOTE_URL_LENGTH + 1)])]]];
        yield 'worktrees that are not a list' => [['worktrees' => 'main']];
        yield 'too many worktrees' => [['worktrees' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_WORKTREES + 1, self::worktree())]];
        yield 'a worktree with a project id that is not a uuid' => [['worktrees' => [self::worktree(['projectId' => 'loupe'])]]];
        yield 'a worktree with no branch' => [['worktrees' => [self::worktree(['branch' => null])]]];
        yield 'a worktree branch that is too long' => [['worktrees' => [self::worktree(['branch' => str_repeat('a', BridgeWorktreeInput::MAX_BRANCH_LENGTH + 1)])]]];
        yield 'a worktree with no path' => [['worktrees' => [self::worktree(['path' => ''])]]];
        yield 'a worktree path that is too long' => [['worktrees' => [self::worktree(['path' => str_repeat('a', BridgeWorktreeInput::MAX_PATH_LENGTH + 1)])]]];
        yield 'capabilities that are not a list' => [['capabilities' => 'commands']];
        yield 'capabilities keyed by name' => [['capabilities' => ['commands' => 'commands']]];
        yield 'too many capabilities' => [['capabilities' => array_map(static fn (int $i): string => 'c'.$i, range(0, RecordBridgeHeartbeatRequest::MAX_CAPABILITIES))]];
        yield 'a capability with an upper-case name' => [['capabilities' => ['Commands']]];
        yield 'a capability name with a trailing newline' => [['capabilities' => ["commands\n"]]];
        yield 'a capability name that is too long' => [['capabilities' => [str_repeat('a', 41)]]];
        yield 'a capability that is not a string' => [['capabilities' => [7]]];
        yield 'work kinds that are not a list' => [['workKinds' => 'fix']];
        yield 'work kinds keyed by name' => [['workKinds' => ['fix' => 'fix']]];
        yield 'too many work kinds' => [['workKinds' => array_map(static fn (int $i): string => 'k'.$i, range(0, RecordBridgeHeartbeatRequest::MAX_WORK_KINDS))]];
        yield 'a work kind with an upper-case name' => [['workKinds' => ['Fix']]];
        yield 'a work kind name with a trailing newline' => [['workKinds' => ["fix\n"]]];
        yield 'a work kind that is not a string' => [['workKinds' => [7]]];
        yield 'a work kind with an empty subject' => [['workKinds' => [':fix']]];
        yield 'a work kind with a subject and no kind' => [['workKinds' => ['analysis:']]];
        yield 'a work kind with two subjects' => [['workKinds' => ['a:b:c']]];
        yield 'a pause state as text' => [['paused' => 'yes']];
        yield 'work claims that are not a list' => [['workClaims' => 'claims']];
        yield 'work claims keyed by id' => [['workClaims' => ['first' => self::workClaim()]]];
        yield 'too many work claims' => [['workClaims' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_WORK_CLAIMS + 1, self::workClaim())]];
        yield 'a work claim that is not an object' => [['workClaims' => ['claim']]];
        yield 'a work claim with no id' => [['workClaims' => [self::workClaim(['id' => null])]]];
        yield 'a work claim id that is not a uuid' => [['workClaims' => [self::workClaim(['id' => 'loupe'])]]];
        yield 'a work claim with no token' => [['workClaims' => [self::workClaim(['claimToken' => null])]]];
        yield 'a work claim token that is not a uuid' => [['workClaims' => [self::workClaim(['claimToken' => 'secret'])]]];
        yield 'host samples that are not a list' => [['hostSamples' => 'samples']];
        yield 'host samples keyed by time' => [['hostSamples' => ['now' => self::sample()]]];
        yield 'too many host samples' => [['hostSamples' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_HOST_SAMPLES + 1, self::sample())]];
        yield 'a host sample that is not an object' => [['hostSamples' => ['sample']]];
        yield 'a host sample with no time' => [['hostSamples' => [self::sample(['sampledAt' => null])]]];
        yield 'a host sample time that is not a date' => [['hostSamples' => [self::sample(['sampledAt' => 'yesterday-ish'])]]];
        yield 'a host sample with no cpu list' => [['hostSamples' => [self::sample(['cpuPct' => null])]]];
        yield 'a host sample cpu list keyed by core' => [['hostSamples' => [self::sample(['cpuPct' => ['a' => 5]])]]];
        yield 'a host sample with too many cores' => [['hostSamples' => [self::sample(['cpuPct' => array_fill(0, BridgeHostSampleInput::MAX_CORES + 1, 1)])]]];
        yield 'a host sample cpu use above 100' => [['hostSamples' => [self::sample(['cpuPct' => [100.5]])]]];
        yield 'a host sample cpu use below 0' => [['hostSamples' => [self::sample(['cpuPct' => [-1]])]]];
        yield 'a host sample cpu use as text' => [['hostSamples' => [self::sample(['cpuPct' => ['12']])]]];
        yield 'a host sample with no memory use' => [['hostSamples' => [self::sample(['memUsed' => null])]]];
        yield 'a host sample with a negative memory total' => [['hostSamples' => [self::sample(['memTotal' => -1])]]];
        yield 'a host sample with no swap use' => [['hostSamples' => [self::sample(['swapUsed' => null])]]];
        yield 'a host sample memory use as text' => [['hostSamples' => [self::sample(['memUsed' => 'lots'])]]];
        yield 'a host sample battery above 100' => [['hostSamples' => [self::sample(['batteryPct' => 101])]]];
        yield 'a host sample power state as text' => [['hostSamples' => [self::sample(['onAc' => 'yes'])]]];
        yield 'no projects' => [['projects' => null]];
        yield 'projects that are not a list' => [['projects' => 'loupe']];
        yield 'a project that is not a uuid' => [['projects' => ['loupe']]];
        yield 'a blank project' => [['projects' => ['']]];
        yield 'too many projects' => [['projects' => array_map(
            static fn (): string => (string) Uuid::v7(),
            range(0, RecordBridgeHeartbeatRequest::MAX_PROJECTS),
        )]];
        yield 'no cli version' => [['cliVersion' => null]];
        yield 'a blank cli version' => [['cliVersion' => '   ']];
        yield 'a cli version that is too long' => [['cliVersion' => str_repeat('a', Bridge::MAX_CLI_VERSION_LENGTH + 1)]];
        yield 'an unknown update state' => [['update' => ['state' => 'exploded']]];
        yield 'an update with no state' => [['update' => ['version' => '1.3.0']]];
        yield 'an update version that is too long' => [['update' => ['state' => 'updating', 'version' => str_repeat('1', Bridge::MAX_UPDATE_VERSION_LENGTH + 1)]]];
        yield 'a name that is too long' => [['name' => str_repeat('a', Bridge::MAX_NAME_LENGTH + 1)]];
        yield 'a name with a control character' => [['name' => "lap\x01top"]];
        yield 'a name that is not a string' => [['name' => 7]];
        yield 'a git identity with a bad project id' => [['gitIdentities' => [self::identity(['projectId' => 'loupe'])]]];
        yield 'a git identity with no project id' => [['gitIdentities' => [self::identity(['projectId' => null])]]];
        yield 'a git identity with an unknown mode' => [['gitIdentities' => [self::identity(['mode' => 'ssh'])]]];
        yield 'a git identity with no mode' => [['gitIdentities' => [self::identity(['mode' => null])]]];
        yield 'a git identity with a commit name that is too long' => [['gitIdentities' => [self::identity(['commitName' => str_repeat('a', 256)])]]];
        yield 'a git identity with a login with a leading hyphen' => [['gitIdentities' => [self::identity(['pushLogin' => '-acme'])]]];
        yield 'a git identity with a login that is too long' => [['gitIdentities' => [self::identity(['pushLogin' => str_repeat('a', 40)])]]];
        yield 'a git identity with a login with an underscore' => [['gitIdentities' => [self::identity(['pushLogin' => 'acme_agent'])]]];
        yield 'a git identity with a user id of zero' => [['gitIdentities' => [self::identity(['githubUserId' => 0])]]];
        yield 'a git identity with a user id that is not a number' => [['gitIdentities' => [self::identity(['githubUserId' => 'abc'])]]];
        yield 'git identities that are not a list' => [['gitIdentities' => 'loupe']];
        yield 'too many git identities' => [['gitIdentities' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_GIT_IDENTITIES + 1, self::identity())]];
        yield 'a negative git identities age' => [['gitIdentitiesAge' => -1]];
        yield 'a git identities age that is too large' => [['gitIdentitiesAge' => RecordBridgeHeartbeatRequest::MAX_GIT_IDENTITIES_AGE + 1]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_heartbeat_is_refused(array $overrides): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-invalid@example.com');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, (string) Uuid::v4(), $raw, array_merge(['projects' => [], 'cliVersion' => 'b4e39aa7'], $overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countBridges());
    }

    /** The version is trimmed before it is measured, so the stored value is the one the check read. */
    public function test_the_cli_version_is_stored_trimmed(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-trim@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '  b4e39aa7  ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('b4e39aa7', $this->bridge($owner, $bridgeId)->cliVersion);
    }

    /** The length is measured on the trimmed name, so the stored value is the one the check read. */
    public function test_the_name_is_stored_trimmed(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-trim@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => '  '.str_repeat('a', Bridge::MAX_NAME_LENGTH).'  ']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame(str_repeat('a', Bridge::MAX_NAME_LENGTH), $bridge->name);
        self::assertSame(str_repeat('a', Bridge::MAX_NAME_LENGTH), $bridge->requestedName);
    }

    /** The CLI refuses only control characters, and a refused name fails the whole heartbeat, so the server refuses no more. */
    public function test_a_name_with_a_format_character_is_accepted(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-format@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => "lap\u{200D}top"]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame("lap\u{200D}top", $this->bridge($owner, $bridgeId)->name);
    }

    public function test_a_blank_name_clears_both_names(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-blank@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);
        self::assertSame('laptop', $this->bridge($owner, $bridgeId)->name);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => '   ']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertNull($bridge->name);
        self::assertNull($bridge->requestedName);
    }

    public function test_a_heartbeat_without_a_name_keeps_the_stored_names(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame('laptop', $bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
    }

    public function test_a_name_another_bridge_holds_still_answers_the_heartbeat(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-clash-api@example.com');
        $raw = $this->agentToken($client, $owner);
        $holder = (string) Uuid::v4();
        $claimer = (string) Uuid::v4();

        $this->put($client, $holder, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);
        $this->put($client, $claimer, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $claimer);
        self::assertNull($bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
    }

    public function test_a_bridge_id_that_is_not_a_uuid_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-baduuid@example.com'));

        $this->put($client, 'not-a-uuid', $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countBridges());
    }

    public function test_it_is_absent_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-flag@example.com'));
        $em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countBridges());
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-widget@example.com');
        $project = $this->project($em, $owner, 'Widget Heartbeat');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countBridges());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-mcp@example.com');
        $project = $this->project($em, $owner, 'Heartbeat MCP');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countBridges());
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $client = static::createClient();

        $client->request(
            Request::METHOD_PUT,
            '/api/bridges/'.Uuid::v4().'/heartbeat',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['projects' => [], 'cliVersion' => 'b4e39aa7'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->countBridges());
    }

    /**
     * With the credential unresolved, the listener would key on the address,
     * and the second heartbeat below would pass. A 429 proves the firewall ran
     * first, and the third proves a second account keeps its own budget.
     */
    public function test_the_limit_counts_per_token_because_the_firewall_runs_first(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_bridge_heartbeats', new RateLimiterFactory(
            ['id' => 'agent_bridge_heartbeats', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-limit@example.com');
        $other = $this->user($em, 'heartbeat-limit-other@example.com');
        $first = $this->agentToken($client, $owner);
        $second = $this->agentToken($client, $other);
        $body = ['projects' => [], 'cliVersion' => 'b4e39aa7'];

        $this->put($client, (string) Uuid::v4(), $first, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);

        $this->put($client, (string) Uuid::v4(), $first, $body, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->put($client, (string) Uuid::v4(), $second, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);
    }

    /** The shipped limiter takes 60 heartbeats from one token in a minute and refuses the 61st. */
    public function test_the_shipped_limit_refuses_the_sixty_first_heartbeat_of_a_token(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-capacity@example.com'));
        $body = ['projects' => [], 'cliVersion' => 'b4e39aa7'];

        for ($heartbeat = 1; $heartbeat <= 60; ++$heartbeat) {
            $this->put($client, (string) Uuid::v4(), $raw, $body);
            self::assertResponseStatusCodeSame(200, 'heartbeat '.$heartbeat);
        }

        $this->put($client, (string) Uuid::v4(), $raw, $body);
        self::assertResponseStatusCodeSame(429);
    }

    /** The limiter runs before the payload is mapped, so a flood of invalid bodies still spends the budget. */
    public function test_the_limit_answers_before_the_body_is_validated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_bridge_heartbeats', new RateLimiterFactory(
            ['id' => 'agent_bridge_heartbeats', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-limit-order@example.com'));

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => 'loupe']);
        self::assertResponseStatusCodeSame(422);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => 'loupe']);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function identity(array $overrides = []): array
    {
        return $overrides + ['projectId' => '0199f3a4-0000-7000-8000-000000000001', 'mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'riley-dev', 'githubUserId' => 42];
    }

    public function test_the_git_identities_are_stored_with_the_report_time(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-git-identities@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $projectId = '0199F3A4-0000-7000-8000-000000000001';

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => [
            self::identity(['projectId' => $projectId]),
            self::identity(['mode' => 'agent_account', 'commitName' => '', 'pushLogin' => '', 'githubUserId' => null]),
        ]]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([
            ['projectId' => strtolower($projectId), 'mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'riley-dev', 'githubUserId' => 42],
            ['projectId' => '0199f3a4-0000-7000-8000-000000000001', 'mode' => 'agent_account', 'commitName' => '', 'pushLogin' => '', 'githubUserId' => null],
        ], $bridge->gitIdentities);
        self::assertNotNull($bridge->gitIdentitiesReportedAt);
    }

    public function test_the_git_identities_are_dated_from_the_read_of_the_bridge(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock('2026-10-18 16:00:00');
        static::getContainer()->set('clock', $clock);
        $owner = $this->user($this->em(), 'heartbeat-git-identities-age@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => [self::identity()]]);
        $clock->modify('2026-10-18 16:09:00');
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => [self::identity()], 'gitIdentitiesAge' => 540]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('2026-10-18 16:00:00', $this->bridge($owner, $bridgeId)->gitIdentitiesReportedAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_heartbeat_carries_a_git_identity_for_each_project_it_can_name(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-git-identities-many@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_PROJECTS, self::identity())]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(RecordBridgeHeartbeatRequest::MAX_PROJECTS, $this->bridge($owner, $bridgeId)->gitIdentities ?? []);
    }

    public function test_a_heartbeat_without_git_identities_keeps_the_stored_rows(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-git-identities-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => [self::identity()]]);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'capabilities' => ['git-identities']]);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'capabilities' => ['git-identities'], 'gitIdentities' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->bridge($owner, $bridgeId)->gitIdentities ?? []);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function olderCliHeartbeats(): iterable
    {
        yield 'capabilities without git-identities' => [['capabilities' => ['commands']]];
        yield 'no capabilities' => [[]];
    }

    /** @param array<string, mixed> $heartbeat */
    #[DataProvider('olderCliHeartbeats')]
    public function test_a_heartbeat_from_an_older_cli_clears_the_git_identities(array $heartbeat): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-git-identities-older-cli@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'capabilities' => ['git-identities'], 'gitIdentities' => [self::identity()]]);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', ...$heartbeat]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertNull($bridge->gitIdentities);
        self::assertNull($bridge->gitIdentitiesReportedAt);
    }

    public function test_an_empty_list_clears_the_git_identities(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-git-identities-clear@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => [self::identity()]]);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'gitIdentities' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->bridge($owner, $bridgeId)->gitIdentities);
    }

    public function test_the_push_login_of_an_old_bridge_is_accepted_and_ignored(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-old-push-login@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'pushLogin' => 'Acme-Agent-2']);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->bridge($owner, $bridgeId)->gitIdentities);
    }

    /** @param array<string, mixed> $payload */
    private function put(KernelBrowser $client, string $bridgeId, string $raw, array $payload, string $clientIp = '127.0.0.1'): void
    {
        $client->request(
            Request::METHOD_PUT,
            '/api/bridges/'.$bridgeId.'/heartbeat',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => $clientIp,
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function bridge(User $owner, string $id): Bridge
    {
        $em = $this->em();
        $em->clear();
        $bridges = static::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        $bridge = $bridges->findOneByOwnerAndId($owner, Uuid::fromString($id));
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }

    /** @return array<mixed> */
    private function reply(KernelBrowser $client): array
    {
        $reply = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reply);

        return $reply;
    }

    private function leaseUntil(WorkRequest $request): ?string
    {
        $em = $this->em();
        $em->clear();

        return $em->find(WorkRequest::class, $request->id)?->leaseUntil?->format('Y-m-d H:i:s');
    }

    private function countBridges(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridges');
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

    /** @return list<array<string, mixed>> */
    private function samples(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT bridge_id, sampled_at, cpu_pct::text AS cpu_pct, mem_used, mem_total, swap_used, battery_pct, on_ac FROM bridge_host_samples ORDER BY sampled_at',
        );
    }
}
