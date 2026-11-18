<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKey;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Says whether the current call comes from a bridge worker, and which run. The
 * run comes only from the run credential that Loupe issued. The session header
 * is a claim of the caller, so it can only make a call weaker: a header that
 * names a worker run of the project marks the call as a worker call, and it
 * never names a run.
 */
final readonly class CallerRun
{
    /** The header the `loupe mcp` proxy sends on every request. */
    public const string SESSION_HEADER = 'X-Loupe-Session';

    public function __construct(
        private TokenStorageInterface $tokens,
        private RequestStack $requests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /** The bridge and the run key of the run credential, or null when the call holds none. */
    public function workerRunKey(): ?WorkerRunKey
    {
        $credential = AuthenticatedCredential::of($this->tokens->getToken());
        if (null === $credential?->workerBridgeId || null === $credential->workerRunKey) {
            return null;
        }

        return new WorkerRunKey($credential->workerBridgeId, $credential->workerRunKey);
    }

    /** The session of the run credential, or null when the call holds none. */
    public function workerSession(): ?Uuid
    {
        return AuthenticatedCredential::of($this->tokens->getToken())?->workerSessionId;
    }

    /** The run of the credential in the project, or null when the call holds no credential or the run is not reported yet. */
    public function workerRun(Project $project): ?WorkerRun
    {
        $key = $this->workerRunKey();

        return null === $key ? null : $this->workerRuns->findWorkerByRunKey($project, $key->bridgeId, $key->runKey);
    }

    public function isWorker(Project $project): bool
    {
        return null !== $this->workerRunKey() || $this->headerNamesWorker($project);
    }

    /** True when the session header names a worker run of the project. The header is a claim, so it only ever makes a call weaker. */
    public function headerNamesWorker(Project $project): bool
    {
        $header = $this->requests->getCurrentRequest()?->headers->get(self::SESSION_HEADER);

        return null !== $header
            && Uuid::isValid($header)
            && null !== $this->workerRuns->findNewestWorkerOfSession($project, Uuid::fromString($header));
    }
}
