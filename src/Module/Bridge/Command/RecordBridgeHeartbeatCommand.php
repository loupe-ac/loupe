<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Module\Bridge\ValueObject\BridgeSessionReading;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-import-type HookRow from Bridge
 * @phpstan-import-type WorkerPoolRow from Bridge
 * @phpstan-import-type AccountRow from Bridge
 * @phpstan-import-type CommandEntryRow from Bridge
 * @phpstan-import-type WorktreeRow from Bridge
 * @phpstan-import-type RepositoryRow from Bridge
 * @phpstan-import-type GitIdentityRow from Bridge
 */
final readonly class RecordBridgeHeartbeatCommand
{
    /**
     * @param list<string>                 $projects            project ids as the bridge sent them, which may name projects the owner does not hold
     * @param list<HookRow>|null           $hooks               null keeps the stored rows, because a bridge that predates hooks sends none
     * @param list<WorkerPoolRow>|null     $workerPools         null keeps the stored rows, because a bridge that predates worker pools sends none
     * @param bool|null                    $paused              null keeps the stored value, because a bridge that predates the pause sends none
     * @param list<string>|null            $capabilities        null keeps the stored names, because a bridge that predates capabilities sends none
     * @param list<array{Uuid, Uuid}>|null $workClaims          pairs of a request id and its claim token. Null from a bridge that predates work requests, which renews nothing
     * @param string|null                  $name                null keeps the stored names, because a bridge that predates names sends none; '' clears both; any other value claims the name
     * @param list<BridgeHostSampleReport> $hostSamples         stored only while host sampling is on
     * @param list<AccountRow>|null        $accounts            null keeps the stored rows, because a bridge that predates account checks sends none
     * @param list<CommandEntryRow>|null   $commandEntries      null keeps the stored rows, because a bridge that predates command entries sends none
     * @param list<WorktreeRow>|null       $worktrees           null keeps the stored rows, because a bridge that predates worktrees sends none
     * @param list<RepositoryRow>|null     $repositories        null keeps the stored rows, because a bridge that predates repositories sends none
     * @param list<string>                 $workKinds           empty from a bridge that predates work kinds, which then runs no kind
     * @param list<GitIdentityRow>|null    $gitIdentities       null keeps the stored rows when the capabilities name git-identities, and clears them otherwise
     * @param list<BridgeSessionReading>   $interactiveSessions what the bridge saw of the sessions the last reply asked it to watch
     * @param int                          $gitIdentitiesAge    the seconds since the bridge read $gitIdentities, which dates the rows
     * @param string|null                  $accessTokenId       the access token that signed the heartbeat. Null keeps the stored login
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public array $projects,
        public string $cliVersion,
        public ?CliUpdateState $updateState = null,
        public ?string $updateVersion = null,
        public ?array $hooks = null,
        public ?array $workerPools = null,
        public ?bool $paused = null,
        public ?array $capabilities = null,
        public ?CliInstallMethod $installMethod = null,
        public ?array $workClaims = null,
        public ?string $name = null,
        public array $hostSamples = [],
        public ?array $accounts = null,
        public ?array $commandEntries = null,
        public ?array $worktrees = null,
        public array $workKinds = [],
        public ?array $repositories = null,
        public ?array $gitIdentities = null,
        public int $gitIdentitiesAge = 0,
        public array $interactiveSessions = [],
        public ?string $accessTokenId = null,
    ) {
    }
}
