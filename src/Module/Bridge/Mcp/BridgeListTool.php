<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Bridge\Command\ListOpenWorkerRunsCommand;
use App\Module\Bridge\Command\ListOpenWorkerRunsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\RefusedRequest;
use App\Module\Bridge\View\AgentConnection;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the bridges that follow the project.
 *
 * @phpstan-import-type CommandEntryRow from Bridge
 * @phpstan-import-type GitIdentityRow from Bridge
 *
 * @phpstan-type OpenRun array{runId: string, subjectType: string, subjectId: string, cardNumber: ?int, workKind: ?string, state: string}
 * @phpstan-type RefusedRequestRow array{workRequestId: string, subjectType: string, subjectId: string, kind: string, account: ?string}
 * @phpstan-type BridgeRow array{bridgeId: string, name: ?string, requestedName: ?string, gitIdentity: array{mode: 'agent_account'|'app_token'|'machine', commitName: string, pushLogin: string, githubUserId: ?int}|null, liveness: 'live'|'quiet', quietCause: 'login-expired'|'unknown'|null, lastSeenAt: ?string, cliVersion: string, pauseRequested: bool, pausedReported: ?bool, takesCommands: bool, takesReruns: bool, acceptsEntries: bool, commandEntries: list<CommandEntryRow>|null, workerPools: list<array{name: string, size: int, inUse: int, queued: int}>|null, workerPoolsReportedAt: ?string, accounts: list<array{name: string, harness: string, state: 'ready'|'failing', reason: ?string, used: bool}>|null, accountsReportedAt: ?string, openRuns: list<OpenRun>, refusedRequests: list<RefusedRequestRow>}
 */
#[McpTool(name: self::NAME, description: 'List the CLI bridges that follow this project. A bridge runs the workers of the project on a machine. Each bridge has bridgeId, name, requestedName, gitIdentity, liveness, quietCause, lastSeenAt, cliVersion, pauseRequested, pausedReported, takesCommands, takesReruns, acceptsEntries, commandEntries, workerPools, workerPoolsReportedAt, accounts, accountsReportedAt, openRuns and refusedRequests. name is the name the bridge holds, or null. requestedName is the name its last heartbeat asked for, or null. A requestedName with a null name means another bridge of the owner already holds that name. gitIdentity is the git identity of the bridge for this project, or null when it reported none. It has mode, commitName, pushLogin and githubUserId. mode is agent_account, app_token or machine. pushLogin is the GitHub login the pushes use, or empty when it is unknown. githubUserId is the numeric id of that login, or null. liveness is live while the bridge sends its heartbeat, and quiet when it missed several. quietCause is null for a live bridge, login-expired when a quiet bridge lost the login that can no longer refresh, and unknown for any other quiet bridge. lastSeenAt is the time of its last heartbeat. pauseRequested says that a person asked the bridge to start no new work. pausedReported says whether the bridge reported that it is paused, or is null when it reported nothing. takesCommands is false for an older bridge, which cannot resume or stop a run. takesReruns is false for a bridge that cannot run a failed command run again. acceptsEntries is true when the bridge adds a proposed command entry to its rule file after the owner confirms. commandEntries lists each command entry the bridge can run, with name, description, params, run and timeout, as its last heartbeat reported them, or is null. Each param has name, pattern and description, and a value must match its pattern whole. run is the argv with its placeholders. workerPools lists each pool of the bridge with its name, size, inUse and queued counts, as its last heartbeat reported them, or is null. accounts lists each account the bridge checked with name, harness, state ready or failing, reason, and used, as its last heartbeat reported them, or is null. used is false for an account that no rule uses, which turns off no rule when it fails. accountsReportedAt is the time of the heartbeat that carried the accounts. openRuns lists the queued, resumed, preparing, running and stopping runs of this project on the bridge, each with runId, subjectType, subjectId, cardNumber, workKind and state. subjectType names what the run is about, such as card, and subjectId is its id. cardNumber is null when the subject is no card. workKind is the kind of the work request the run ran, or null. refusedRequests lists the open work requests of this project that the server refused to the bridge, each with workRequestId, subjectType, subjectId, kind and account. The server refuses a claim when the account it names, or the lack of one, does not match the account that runs the work kind. account is the account the refused claim named, or null when it named none. The bridge cannot take those requests until it reports a new CLI version or new accounts.')]
final readonly class BridgeListTool
{
    public const string NAME = 'bridge_list';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListAgentsHandler $listAgents,
        private ListOpenWorkerRunsHandler $listOpenRuns,
    ) {
    }

    /**
     * The list is wrapped in a `bridges` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object.
     *
     * @return array{bridges: list<BridgeRow>}
     */
    public function __invoke(): array
    {
        try {
            $project = $this->subjects->requireProject();
            $connections = ($this->listAgents)(new ListAgentsCommand($project))->connections;

            $openRuns = [];
            foreach (($this->listOpenRuns)(new ListOpenWorkerRunsCommand($project))->runs as $run) {
                if (null !== $run->bridgeId) {
                    $openRuns[$run->bridgeId->toRfc4122()][] = ['runId' => (string) $run->id, 'subjectType' => $run->subjectType, 'subjectId' => $run->subjectId->toRfc4122(), 'cardNumber' => $run->cardNumber, 'workKind' => $run->workKind, 'state' => $run->state->value];
                }
            }

            return ['bridges' => array_map(
                static fn (AgentConnection $connection): array => [
                    'bridgeId' => $connection->bridge->id->toRfc4122(),
                    'name' => $connection->bridge->name,
                    'requestedName' => $connection->bridge->requestedName,
                    'gitIdentity' => self::gitIdentityOf($connection->bridge->gitIdentities, (string) $project->id),
                    'liveness' => $connection->status->quiet ? 'quiet' : 'live',
                    'quietCause' => $connection->status->quiet ? ($connection->status->loginExpired ? 'login-expired' : 'unknown') : null,
                    'lastSeenAt' => $connection->status->lastSeenAt?->format(\DATE_ATOM),
                    'cliVersion' => $connection->bridge->cliVersion,
                    'pauseRequested' => $connection->bridge->pauseRequested,
                    'pausedReported' => $connection->bridge->pausedReported,
                    'takesCommands' => $connection->bridge->takesCommands(),
                    'takesReruns' => $connection->bridge->takesReruns(),
                    'acceptsEntries' => $connection->bridge->acceptsEntries(),
                    'commandEntries' => $connection->bridge->commandEntries,
                    'workerPools' => $connection->bridge->workerPools,
                    'workerPoolsReportedAt' => $connection->bridge->workerPoolsReportedAt?->format(\DATE_ATOM),
                    'accounts' => null === $connection->bridge->accounts ? null : array_map(static fn (array $account): array => $account + ['used' => true], $connection->bridge->accounts),
                    'accountsReportedAt' => $connection->bridge->accountsReportedAt?->format(\DATE_ATOM),
                    'openRuns' => $openRuns[$connection->bridge->id->toRfc4122()] ?? [],
                    'refusedRequests' => array_map(
                        static fn (RefusedRequest $refusal): array => ['workRequestId' => $refusal->workRequestId->toRfc4122(), 'subjectType' => $refusal->subjectType, 'subjectId' => $refusal->subjectId->toRfc4122(), 'kind' => $refusal->kind, 'account' => $refusal->account],
                        $connection->refusals,
                    ),
                ],
                $connections,
            )];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The bridges could not be read. The error has been logged.', previous: $e);
        }
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
