<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One heartbeat, as the bridge sends it.
 *
 * @phpstan-import-type HookRow from Bridge
 * @phpstan-import-type WorkerPoolRow from Bridge
 * @phpstan-import-type AccountRow from Bridge
 * @phpstan-import-type CommandEntryRow from Bridge
 * @phpstan-import-type WorktreeRow from Bridge
 * @phpstan-import-type RepositoryRow from Bridge
 * @phpstan-import-type GitIdentityRow from Bridge
 */
final class RecordBridgeHeartbeatRequest
{
    /** Far above the projects one account runs, and small enough to bound the JSON column. */
    public const int MAX_PROJECTS = 500;

    /** Far above the hooks one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_HOOKS = 100;

    /** Far above the pools one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_WORKER_POOLS = 50;

    /** Far above the accounts one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_ACCOUNTS = 50;

    /** Far above the command entries one bridge holds, and small enough to bound the JSON column. */
    public const int MAX_COMMAND_ENTRIES = 50;

    /** Far above the worktrees one bridge holds, and small enough to bound the JSON column. */
    public const int MAX_WORKTREES = 200;

    /** Far above the projects one bridge follows, and small enough to bound the JSON column. */
    public const int MAX_REPOSITORIES = 500;

    /** Far above the projects one bridge follows on a machine, and small enough to bound the JSON column. */
    public const int MAX_GIT_IDENTITIES = 50;

    /** Far above the features one bridge reports, and small enough to bound the JSON column. */
    public const int MAX_CAPABILITIES = 20;

    /** Far above the kinds of work one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_WORK_KINDS = 50;

    /** Far above the work one bridge runs at a time, and small enough to bound the renewal statement. */
    public const int MAX_WORK_CLAIMS = 200;

    /** Twelve hours of one sample a minute, which a bridge holds while the server is away. */
    public const int MAX_HOST_SAMPLES = 720;

    /**
     * @param list<string>|null                  $projects
     * @param list<BridgeHookInput>|null         $hooks          null from a bridge that predates hooks
     * @param list<BridgeWorkerPoolInput>|null   $workerPools    null from a bridge that predates worker pools
     * @param bool|null                          $paused         null from a bridge that predates the pause
     * @param list<string>|null                  $capabilities   null from a bridge that predates capabilities
     * @param list<BridgeWorkClaimInput>|null    $workClaims     null from a bridge that predates work requests
     * @param string|null                        $name           null from a bridge that predates names; blank clears the name
     * @param list<BridgeHostSampleInput>|null   $hostSamples    null from a bridge that predates host samples
     * @param string|null                        $pushLogin      sent by an old bridge, and ignored; the git identity rows replace it
     * @param list<BridgeAccountInput>|null      $accounts       null from a bridge that predates account checks
     * @param list<BridgeCommandEntryInput>|null $commandEntries null from a bridge that predates command entries
     * @param list<BridgeWorktreeInput>|null     $worktrees      null from a bridge that predates worktrees
     * @param list<BridgeRepositoryInput>|null   $repositories   null from a bridge that predates repositories
     * @param list<string>|null                  $workKinds      null from a bridge that predates work kinds
     * @param list<BridgeGitIdentityInput>|null  $gitIdentities  null from a bridge that predates git identities
     */
    public function __construct(
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        #[Assert\Count(max: self::MAX_PROJECTS)]
        #[Assert\NotNull]
        #[Assert\Type('list')]
        public ?array $projects = null,

        #[Assert\Length(max: Bridge::MAX_CLI_VERSION_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $cliVersion = null,

        #[Assert\Valid]
        public ?CliUpdateInput $update = null,

        #[Assert\All([new Assert\Type(BridgeHookInput::class)])]
        #[Assert\Count(max: self::MAX_HOOKS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $hooks = null,

        #[Assert\All([new Assert\Type(BridgeWorkerPoolInput::class)])]
        #[Assert\Count(max: self::MAX_WORKER_POOLS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $workerPools = null,
        public ?bool $paused = null,

        #[Assert\All([new Assert\Type('string'), new Assert\Regex(pattern: Bridge::CAPABILITY_PATTERN)])]
        #[Assert\Count(max: self::MAX_CAPABILITIES)]
        #[Assert\Type('list')]
        public ?array $capabilities = null,

        #[Assert\All([new Assert\Type(BridgeWorkClaimInput::class)])]
        #[Assert\Count(max: self::MAX_WORK_CLAIMS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $workClaims = null,

        #[Assert\Length(max: Bridge::MAX_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\Regex(pattern: Bridge::NAME_PATTERN, normalizer: 'trim')]
        public ?string $name = null,

        #[Assert\All([new Assert\Type(BridgeHostSampleInput::class)])]
        #[Assert\Count(max: self::MAX_HOST_SAMPLES)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $hostSamples = null,
        public ?string $pushLogin = null,

        #[Assert\All([new Assert\Type(BridgeAccountInput::class)])]
        #[Assert\Count(max: self::MAX_ACCOUNTS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $accounts = null,

        #[Assert\All([new Assert\Type(BridgeCommandEntryInput::class)])]
        #[Assert\Count(max: self::MAX_COMMAND_ENTRIES)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $commandEntries = null,

        #[Assert\All([new Assert\Type(BridgeWorktreeInput::class)])]
        #[Assert\Count(max: self::MAX_WORKTREES)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $worktrees = null,

        #[Assert\All([new Assert\Type(BridgeRepositoryInput::class)])]
        #[Assert\Count(max: self::MAX_REPOSITORIES)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $repositories = null,

        #[Assert\All([new Assert\Type('string'), new Assert\Regex(pattern: Bridge::WORK_KIND_PATTERN)])]
        #[Assert\Count(max: self::MAX_WORK_KINDS)]
        #[Assert\Type('list')]
        public ?array $workKinds = null,

        #[Assert\All([new Assert\Type(BridgeGitIdentityInput::class)])]
        #[Assert\Count(max: self::MAX_GIT_IDENTITIES)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $gitIdentities = null,
    ) {
    }

    /**
     * The pairs of a request id and its claim token, once each. Null when the
     * bridge sent no list.
     *
     * @return list<array{Uuid, Uuid}>|null
     */
    public function workClaims(): ?array
    {
        if (null === $this->workClaims) {
            return null;
        }

        $claims = [];
        foreach ($this->workClaims as $claim) {
            $id = Uuid::fromString($claim->id ?? '');
            $token = Uuid::fromString($claim->claimToken ?? '');
            $claims[$id->toRfc4122().' '.$token->toRfc4122()] = [$id, $token];
        }

        return array_values($claims);
    }

    /** @return list<BridgeHostSampleReport> */
    public function hostSamples(): array
    {
        return array_map(static fn (BridgeHostSampleInput $sample): BridgeHostSampleReport => $sample->report(), array_values($this->hostSamples ?? []));
    }

    /**
     * The capability names once each. Null when the bridge sent no report.
     *
     * @return list<string>|null
     */
    public function capabilities(): ?array
    {
        if (null === $this->capabilities) {
            return null;
        }

        return array_values(array_unique($this->capabilities));
    }

    /**
     * The work kinds once each. Empty when the bridge sent no report.
     *
     * @return list<string>
     */
    public function workKinds(): array
    {
        return array_values(array_unique($this->workKinds ?? []));
    }

    /**
     * The hook rows as the bridge row stores them, with every time in one
     * format. Null when the bridge sent no report.
     *
     * @return list<HookRow>|null
     */
    public function hooks(): ?array
    {
        if (null === $this->hooks) {
            return null;
        }

        return array_map(static fn (BridgeHookInput $hook): array => [
            'package' => trim($hook->package ?? ''),
            'ref' => trim($hook->ref ?? ''),
            'event' => $hook->event ?? '',
            'lastRunAt' => null === $hook->lastRunAt ? null : new \DateTimeImmutable($hook->lastRunAt)->format(\DateTimeInterface::ATOM),
            'outcome' => $hook->outcome ?? '',
            'error' => '' === trim($hook->error ?? '') ? null : trim($hook->error ?? ''),
        ], array_values($this->hooks));
    }

    /**
     * The worker pool rows as the bridge row stores them. Null when the bridge
     * sent no report.
     *
     * @return list<WorkerPoolRow>|null
     */
    public function workerPools(): ?array
    {
        if (null === $this->workerPools) {
            return null;
        }

        return array_map(static fn (BridgeWorkerPoolInput $pool): array => [
            'name' => $pool->name ?? '',
            'size' => $pool->size ?? 0,
            'inUse' => $pool->inUse ?? 0,
            'queued' => $pool->queued ?? 0,
        ], array_values($this->workerPools));
    }

    /**
     * The account rows as the bridge row stores them. A reason is kept for a
     * failing account alone. Null when the bridge sent no report.
     *
     * @return list<AccountRow>|null
     */
    public function accounts(): ?array
    {
        if (null === $this->accounts) {
            return null;
        }

        return array_map(static function (BridgeAccountInput $account): array {
            $failing = BridgeAccountInput::STATE_FAILING === $account->state;
            $reason = trim($account->reason ?? '');

            return [
                'name' => $account->name ?? '',
                'harness' => $account->harness ?? '',
                'state' => $failing ? BridgeAccountInput::STATE_FAILING : BridgeAccountInput::STATE_READY,
                'reason' => $failing && '' !== $reason ? $reason : null,
                'used' => $account->used ?? true,
            ];
        }, array_values($this->accounts));
    }

    /**
     * The command entry rows as the bridge row stores them. A blank
     * description is stored as none. Null when the bridge sent no report.
     *
     * @return list<CommandEntryRow>|null
     */
    public function commandEntries(): ?array
    {
        if (null === $this->commandEntries) {
            return null;
        }

        return array_map(static fn (BridgeCommandEntryInput $entry): array => [
            'name' => $entry->name ?? '',
            'description' => '' === trim($entry->description ?? '') ? null : trim($entry->description ?? ''),
            'params' => array_map(static fn (BridgeCommandParamInput $param): array => [
                'name' => $param->name ?? '',
                'pattern' => $param->pattern ?? '',
                'description' => '' === trim($param->description ?? '') ? null : trim($param->description ?? ''),
            ], array_values($entry->params ?? [])),
            'run' => array_values($entry->run ?? []),
            'timeout' => $entry->timeout ?? '',
        ], array_values($this->commandEntries));
    }

    /**
     * The worktree rows as the bridge row stores them, with the project id in
     * canonical form. Null when the bridge sent no report.
     *
     * @return list<WorktreeRow>|null
     */
    public function worktrees(): ?array
    {
        if (null === $this->worktrees) {
            return null;
        }

        return array_map(static fn (BridgeWorktreeInput $worktree): array => [
            'projectId' => Uuid::fromString($worktree->projectId ?? '')->toRfc4122(),
            'branch' => $worktree->branch ?? '',
            'path' => $worktree->path ?? '',
        ], array_values($this->worktrees));
    }

    /**
     * The repository rows as the bridge row stores them, with the project id in
     * canonical form. Null when the bridge sent no report.
     *
     * @return list<RepositoryRow>|null
     */
    public function repositories(): ?array
    {
        if (null === $this->repositories) {
            return null;
        }

        return array_map(static fn (BridgeRepositoryInput $repository): array => [
            'projectId' => Uuid::fromString($repository->projectId ?? '')->toRfc4122(),
            'path' => $repository->path ?? '',
            'remoteUrl' => $repository->remoteUrl ?? '',
        ], array_values($this->repositories));
    }

    /**
     * The git identity rows as the bridge row stores them, with the project id
     * in canonical form. Null when the bridge sent no report.
     *
     * @return list<GitIdentityRow>|null
     */
    public function gitIdentities(): ?array
    {
        if (null === $this->gitIdentities) {
            return null;
        }

        return array_map(static fn (BridgeGitIdentityInput $identity): array => [
            'projectId' => Uuid::fromString($identity->projectId ?? '')->toRfc4122(),
            'mode' => match ($identity->mode) {
                'agent_account' => 'agent_account',
                'app_token' => 'app_token',
                default => 'machine',
            },
            'commitName' => $identity->commitName ?? '',
            'pushLogin' => $identity->pushLogin ?? '',
            'githubUserId' => $identity->githubUserId,
        ], array_values($this->gitIdentities));
    }

    /**
     * The project ids in canonical form, once each, so an upper-case copy of an
     * id cannot slip past the ownership filter as a second entry.
     *
     * @return list<string>
     */
    public function projectIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $id): string => Uuid::fromString($id)->toRfc4122(),
            $this->projects ?? [],
        )));
    }

    /** Null when the bridge sent no name. Trimmed, because the constraints read the trimmed value. */
    public function name(): ?string
    {
        return null === $this->name ? null : trim($this->name);
    }

    /** Trimmed, because the length constraint measured the trimmed value. */
    public function cliVersion(): string
    {
        return trim($this->cliVersion ?? '');
    }
}
