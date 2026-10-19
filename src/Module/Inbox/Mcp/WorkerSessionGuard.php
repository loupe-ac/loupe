<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** Refuses a call from a session that the bridge started as a worker. A worker has no person beside it. */
final readonly class WorkerSessionGuard
{
    public const string REFUSAL = 'A bridge worker cannot answer an inbox item. Ask the owner with inbox_ask.';

    public function __construct(
        private RequestStack $requests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /** @throws ToolCallException when the session of the call is a worker session of the project */
    public function requireNotWorker(Project $project): void
    {
        $session = $this->requests->getCurrentRequest()?->headers->get(AgentRunCause::SESSION_HEADER);
        if (null === $session || !Uuid::isValid($session)) {
            return;
        }
        if (null !== $this->workerRuns->findNewestWorkerOfSession($project, Uuid::fromString($session))) {
            throw new ToolCallException(self::REFUSAL);
        }
    }
}
