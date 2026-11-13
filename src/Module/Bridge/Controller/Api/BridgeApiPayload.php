<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\RefusedRequest;
use App\Module\Bridge\View\AgentConnection;
use App\Module\Bridge\View\BridgeStatus;

/**
 * Shapes bridges for the public API.
 *
 * @phpstan-import-type AccountRow from Bridge
 * @phpstan-import-type GitIdentityRow from Bridge
 */
final readonly class BridgeApiPayload
{
    /**
     * A bridge that serves the project, with the open runs of the project on it and the requests the server refused to it.
     *
     * @param list<WorkerRun> $openRuns the open runs of the project that ran on this bridge
     *
     * @return array<string, mixed>
     */
    public function forProjectBridge(AgentConnection $connection, string $projectId, array $openRuns): array
    {
        $bridge = $connection->bridge;

        return [
            'bridgeId' => $bridge->id->toRfc4122(),
            'name' => $bridge->name,
            'requestedName' => $bridge->requestedName,
            'gitIdentity' => self::gitIdentityOf($bridge->gitIdentities, $projectId),
            'liveness' => $connection->status->quiet ? 'quiet' : 'live',
            'quietCause' => $connection->status->quiet ? ($connection->status->loginExpired ? 'login-expired' : 'unknown') : null,
            'lastSeenAt' => $connection->status->lastSeenAt?->format(\DATE_ATOM),
            'cliVersion' => $bridge->cliVersion,
            'pauseRequested' => $bridge->pauseRequested,
            'pausedReported' => $bridge->pausedReported,
            'takesCommands' => $bridge->takesCommands(),
            'takesReruns' => $bridge->takesReruns(),
            'acceptsEntries' => $bridge->acceptsEntries(),
            'commandEntries' => $bridge->commandEntries,
            'workerPools' => $bridge->workerPools,
            'workerPoolsReportedAt' => $bridge->workerPoolsReportedAt?->format(\DATE_ATOM),
            'accounts' => null === $bridge->accounts ? null : array_map(static fn (array $account): array => self::accountOf($account, $connection), $bridge->accounts),
            'accountsReportedAt' => $bridge->accountsReportedAt?->format(\DATE_ATOM),
            'skills' => $bridge->skills,
            'openRuns' => array_map(static fn (WorkerRun $run): array => [
                'runId' => (string) $run->id,
                'subjectType' => $run->subjectType,
                'subjectId' => $run->subjectId->toRfc4122(),
                'cardNumber' => $run->cardNumber,
                'workKind' => $run->workKind,
                'state' => $run->state->value,
            ], $openRuns),
            'refusedRequests' => array_map(
                static fn (RefusedRequest $refusal): array => ['workRequestId' => $refusal->workRequestId->toRfc4122(), 'subjectType' => $refusal->subjectType, 'subjectId' => $refusal->subjectId->toRfc4122(), 'kind' => $refusal->kind, 'account' => $refusal->account],
                $connection->refusals,
            ),
        ];
    }

    /**
     * A bridge of the owner, with the ids of the projects it serves.
     *
     * @param ?string $onlyProjectId the one project a bound credential may learn about
     *
     * @return array<string, mixed>
     */
    public function forOwnerBridge(Bridge $bridge, BridgeStatus $status, ?string $onlyProjectId): array
    {
        return [
            'bridgeId' => $bridge->id->toRfc4122(),
            'name' => $bridge->name,
            'requestedName' => $bridge->requestedName,
            'liveness' => $status->quiet ? 'quiet' : 'live',
            'quietCause' => $status->quiet ? ($status->loginExpired ? 'login-expired' : 'unknown') : null,
            'lastSeenAt' => $status->lastSeenAt?->format(\DATE_ATOM),
            'cliVersion' => $bridge->cliVersion,
            'pauseRequested' => $bridge->pauseRequested,
            'pausedReported' => $bridge->pausedReported,
            'takesCommands' => $bridge->takesCommands(),
            'takesReruns' => $bridge->takesReruns(),
            'projectIds' => null === $onlyProjectId ? array_values($bridge->projects) : [$onlyProjectId],
        ];
    }

    /**
     * @param AccountRow $account
     *
     * @return array<string, mixed>
     */
    private static function accountOf(array $account, AgentConnection $connection): array
    {
        $name = $account['name'];
        unset($account['failedProjects'], $account['appPrompts']);

        return $account + [
            'used' => true,
            'projectFailure' => $connection->projectFailure($name),
            'otherFailedProjects' => $connection->otherFailedProjects($name),
        ];
    }

    /**
     * @param list<GitIdentityRow>|null $rows
     *
     * @return array{mode: 'agent_account'|'app_token'|'machine', commitName: string, pushLogin: string, githubUserId: ?int}|null
     */
    private static function gitIdentityOf(?array $rows, string $projectId): ?array
    {
        $row = array_find($rows ?? [], static fn (array $candidate): bool => $candidate['projectId'] === $projectId);

        return null === $row ? null : ['mode' => $row['mode'], 'commitName' => $row['commitName'], 'pushLogin' => $row['pushLogin'], 'githubUserId' => $row['githubUserId']];
    }
}
