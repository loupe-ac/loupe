<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Entity\WorkerRunOutputChunk;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\Service\BucketFigures;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunToolCallKind;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/** Fixtures the Bridge module's database tests share. */
trait BridgeScenario
{
    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner, string $name): Project
    {
        $project = new Project(AgentCredential::managed($em, $owner, $owner->id), $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function agentToken(KernelBrowser $browser, User $owner): string
    {
        return AgentCredential::agentToken(static::getContainer(), $owner);
    }

    private function seedRun(
        EntityManagerInterface $em,
        Project $project,
        \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
        int $cardNumber = 1,
        ?int $exitCode = 0,
        ?string $failureReason = null,
        string $output = 'worker output',
        ?string $workKind = 'plan',
        ?Uuid $bridgeId = null,
        ?Uuid $cardId = null,
        ?WorkerRunState $state = null,
        ?Uuid $runKey = null,
        ?bool $hasResult = null,
        WorkerRunKind $kind = WorkerRunKind::Worker,
        ?string $workerPool = null,
        ?Uuid $workRequestId = null,
        \DateTimeImmutable $endedAt = new \DateTimeImmutable('2026-01-01 10:05:00'),
        string $subjectType = WorkSubject::CARD,
        ?string $harness = null,
        ?string $account = null,
        ?string $model = null,
        ?string $harnessSessionId = null,
        ?string $role = null,
        ?string $ruleId = null,
    ): WorkerRun {
        $run = new WorkerRun(
            project: AgentCredential::managed($em, $project, $project->id),
            bridgeId: WorkerRunKind::Interactive === $kind ? $bridgeId : $bridgeId ?? Uuid::v7(),
            subjectType: $subjectType,
            subjectId: $cardId ?? Uuid::v7(),
            cardNumber: WorkSubject::CARD === $subjectType ? $cardNumber : null,
            workKind: $workKind,
            state: $state ?? WorkerRunState::fromOutcome($exitCode, $hasResult),
            runKey: $runKey,
            sessionId: WorkerRunKind::Command === $kind ? null : Uuid::v4(),
            startedAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
            endedAt: $endedAt,
            exitCode: $exitCode,
            hasResult: $hasResult,
            failureReason: $failureReason,
            output: $output,
            receivedAt: $receivedAt,
            kind: $kind,
            workRequestId: $workRequestId,
            ruleId: $ruleId,
            role: $role,
        );
        $run->workerPool = $workerPool;
        $run->recordHarness($harness, $account, $model, $harnessSessionId);
        $em->persist($run);
        $em->flush();
        // What the report endpoint does after it writes the row. A run seeded
        // without this carries a null vector and no search can reach it.
        $this->searchIndexer()->index($run);

        return $run;
    }

    private function seedUsage(
        EntityManagerInterface $em,
        WorkerRun $run,
        string $model = 'claude-opus-5-5',
        WorkerRunUsageSource $source = WorkerRunUsageSource::Reported,
        ?string $costUsd = '0.012345',
        int $inputTokens = 100,
        bool $billedCost = false,
    ): WorkerRunUsage {
        $usage = new WorkerRunUsage($run, $run->project, $run->subjectType, $run->subjectId, $run->workKind, $model, $source, $inputTokens, 20, 300, 40, $costUsd, $billedCost);
        $run->usageSource = $source;
        $em->persist($usage);
        $em->flush();

        return $usage;
    }

    /** A billed run: its usage rows carry tokens and no price, and the billed cost row holds the cost. */
    private function seedBilledCost(
        EntityManagerInterface $em,
        WorkerRun $run,
        BilledCostState $state = BilledCostState::Billed,
        ?string $costUsd = '0.500000',
        ?BilledCostReason $reason = null,
    ): WorkerRunBilledCost {
        $billed = new WorkerRunBilledCost(
            runId: $run->id ?? throw new \LogicException('The run has no id.'),
            runKey: $run->runKey ?? Uuid::v4(),
            project: $run->project,
            subjectType: $run->subjectType,
            subjectId: $run->subjectId,
            runStartedAt: $run->startedAt ?? $run->receivedAt,
        );
        $billed->state = $state;
        $billed->costUsd = $costUsd;
        $billed->reason = $reason;
        $em->persist($billed);
        $em->flush();

        return $billed;
    }

    /**
     * @param array<int|string, int> $times bucket name => milliseconds
     *
     * @return array<int|string, BucketFigures> one call per bucket, with no time of subagents
     */
    private static function figures(array $times): array
    {
        return array_map(static fn (int $ms): BucketFigures => new BucketFigures($ms, $ms, 1), $times);
    }

    /** @param array<int|string, int> $times bucket name => milliseconds */
    private function seedBucketTimes(EntityManagerInterface $em, WorkerRun $run, array $times): void
    {
        foreach ($times as $bucket => $ms) {
            $em->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => (string) $bucket, 'ms' => $ms]);
        }
    }

    /** @param list<array{string, int, ?int, ?int}> $rows bucket, main ms, ms with subagents, calls */
    private function seedBucketFigures(EntityManagerInterface $em, WorkerRun $run, array $rows): void
    {
        foreach ($rows as [$bucket, $ms, $withSubagents, $calls]) {
            $em->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => $bucket, 'ms' => $ms, 'ms_with_subagents' => $withSubagents, 'calls' => $calls]);
        }
    }

    /** With no kind given, Bash is shell and any other tool is tool. */
    private function seedToolCall(WorkerRun $run, int $seq = 1, string $tool = 'Bash', ?WorkerRunToolCallKind $kind = null): void
    {
        $repository = static::getContainer()->get(WorkerRunToolCallRepository::class);
        self::assertInstanceOf(WorkerRunToolCallRepository::class, $repository);
        $repository->insertNew($run, [new WorkerRunToolCallReport(
            seq: $seq,
            tool: $tool,
            kind: $kind ?? ('Bash' === $tool ? WorkerRunToolCallKind::Shell : WorkerRunToolCallKind::Tool),
            startedAt: new \DateTimeImmutable('2026-01-01 10:00:01'),
            durationMs: 1500,
            isError: false,
            inSubagent: false,
            backgroundId: null,
            waitsOn: null,
            signatures: [$tool],
            fullText: null,
        )]);
    }

    /** Stores one chunk at the end of the log of the run. */
    private function seedOutputChunk(WorkerRun $run, string $text, bool $cut = false): WorkerRunOutputChunk
    {
        $em = $this->em();
        $start = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(start_offset + length), 0) FROM bridge_worker_run_output_chunks WHERE run_id = :run', ['run' => (string) $run->id]);
        $chunk = new WorkerRunOutputChunk($run, $start, \strlen($text), $text, $cut);
        $em->persist($chunk);
        $em->flush();

        return $chunk;
    }

    /** @param list<float> $cpuPct */
    private function seedHostSample(
        Bridge $bridge,
        string $sampledAt,
        array $cpuPct = [10.0, 30.0],
        int $memUsed = 1000,
        int $swapUsed = 0,
        ?float $batteryPct = null,
        ?bool $onAc = null,
    ): void {
        $repository = static::getContainer()->get(BridgeHostSampleRepository::class);
        self::assertInstanceOf(BridgeHostSampleRepository::class, $repository);
        $repository->insertNew(
            $bridge->owner->id ?? throw new \LogicException('The owner has no id.'),
            $bridge->id,
            [new BridgeHostSampleReport(new \DateTimeImmutable($sampledAt, new \DateTimeZone('UTC')), $cpuPct, $memUsed, 4000, $swapUsed, $batteryPct, $onAc)],
        );
    }

    private function seedAccountReading(
        EntityManagerInterface $em,
        Bridge $bridge,
        string $readAt,
        string $account = 'work',
        string $window = 'session',
        ?float $usedPercent = 40.0,
        string $resetsAt = '2026-10-07 17:00:00',
        bool $limited = false,
    ): BridgeAccountReading {
        $utc = new \DateTimeZone('UTC');
        $reading = new BridgeAccountReading(Uuid::v7(), $bridge, $account, $window, $usedPercent, new \DateTimeImmutable($resetsAt, $utc), $limited, 'stream', new \DateTimeImmutable($readAt, $utc));
        $em->persist($reading);
        $em->flush();

        return $reading;
    }

    private function countToolCalls(EntityManagerInterface $em): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_tool_calls');
    }

    private function countUsage(EntityManagerInterface $em): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_usage');
    }

    /**
     * @param list<string>                                                                                            $projects
     * @param list<array{name: string, size: int, inUse: int, queued: int}>|null                                      $workerPools
     * @param list<array{name: string, harness: string, state: 'ready'|'failing', reason: ?string, used?: bool}>|null $accounts
     */
    private function seedBridge(
        EntityManagerInterface $em,
        User $owner,
        ?Uuid $id = null,
        array $projects = [],
        string $cliVersion = 'b4e39aa7',
        \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable(),
        ?array $workerPools = null,
        ?\DateTimeImmutable $workerPoolsReportedAt = null,
        ?array $accounts = null,
        ?\DateTimeImmutable $accountsReportedAt = null,
    ): Bridge {
        $bridge = new Bridge(AgentCredential::managed($em, $owner, $owner->id), $id ?? Uuid::v4(), $projects, $cliVersion, $lastSeenAt);
        $bridge->workerPools = $workerPools;
        $bridge->workerPoolsReportedAt = $workerPoolsReportedAt;
        $bridge->accounts = $accounts;
        $bridge->accountsReportedAt = $accountsReportedAt;
        $em->persist($bridge);
        $em->flush();

        return $bridge;
    }

    private function seedCommand(
        EntityManagerInterface $em,
        WorkerRun $run,
        BridgeCommandState $state = BridgeCommandState::Pending,
        \DateTimeImmutable $requestedAt = new \DateTimeImmutable('2026-09-29 12:00:00'),
        ?\DateTimeImmutable $expiresAt = null,
        BridgeCommandKind $kind = BridgeCommandKind::StopRun,
        ?User $requestedBy = null,
    ): BridgeCommand {
        $command = new BridgeCommand(
            owner: $run->project->owner,
            bridgeId: $run->bridgeId ?? throw new \LogicException('A command needs a run with a bridge.'),
            project: $run->project,
            workerRun: $run,
            kind: $kind,
            requestedBy: $requestedBy,
            requestedAt: $requestedAt,
            expiresAt: $expiresAt ?? $requestedAt->modify('+15 minutes'),
        );
        $command->state = $state;
        $em->persist($command);
        $em->flush();

        return $command;
    }

    private function countCommands(EntityManagerInterface $em): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_commands');
    }

    /** @param array<string, string>|null $params */
    private function seedWorkRequest(
        EntityManagerInterface $em,
        Project $project,
        ?Uuid $cardId = null,
        string $kind = 'implement',
        ?string $capability = null,
        WorkRequestState $state = WorkRequestState::Open,
        \DateTimeImmutable $createdAt = new \DateTimeImmutable('2026-10-01 12:00:00'),
        ?Uuid $bridgeId = null,
        ?Uuid $claimToken = null,
        ?\DateTimeImmutable $leaseUntil = null,
        string $ruleId = 'implement-on-entry',
        ?WorkSubject $subject = null,
        ?\DateTimeImmutable $reopenedAt = null,
        ?string $prompt = null,
        ?Uuid $targetBridgeId = null,
        ?string $branch = null,
        ?array $params = null,
        ?string $role = null,
    ): WorkRequest {
        $subject ??= WorkSubject::card($cardId ?? Uuid::v7());
        $request = new WorkRequest(
            project: AgentCredential::managed($em, $project, $project->id),
            subjectType: $subject->type,
            subjectId: $subject->id,
            cardNumber: $subject->isCard() ? 7 : null,
            kind: $kind,
            capability: $capability,
            ruleId: $ruleId,
            createdAt: $createdAt,
        );
        $request->state = $state;
        $request->bridgeId = $bridgeId;
        $request->claimToken = $claimToken;
        $request->leaseUntil = $leaseUntil;
        $request->reopenedAt = $reopenedAt;
        $request->prompt = $prompt;
        $request->targetBridgeId = $targetBridgeId;
        $request->branch = $branch;
        $request->params = $params;
        $request->role = $role;
        $em->persist($request);
        $em->flush();

        return $request;
    }

    private function searchIndexer(): WorkerRunSearchIndexer
    {
        $indexer = static::getContainer()->get(WorkerRunSearchIndexer::class);
        self::assertInstanceOf(WorkerRunSearchIndexer::class, $indexer);

        return $indexer;
    }

    /** View and manage are both the owner's today, so a checker stands in for a viewer who cannot manage. Call it before the first request or fixture. */
    private function denyManage(): void
    {
        $container = static::getContainer();
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $decisions = $container->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $decisions);
        $real = new AuthorizationChecker($tokens, $decisions);

        $container->set('security.authorization_checker', new readonly class($real) implements AuthorizationCheckerInterface {
            public function __construct(
                private AuthorizationCheckerInterface $real,
            ) {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return ProjectVoter::MANAGE !== $attribute && $this->real->isGranted($attribute, $subject, $accessDecision);
            }
        });
    }
}
