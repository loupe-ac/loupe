<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Module\Bridge\Service\CallerRun;
use App\Module\Project\Entity\Project;
use Mcp\Exception\ToolCallException;

/** Refuses a call from a bridge worker, by its run credential or by a header that names a worker session. A worker has no person beside it. */
final readonly class WorkerSessionGuard
{
    public const string REFUSAL = 'A bridge worker cannot answer an inbox item. Ask the owner with inbox_ask.';

    public function __construct(
        private CallerRun $callerRun,
    ) {
    }

    /** @throws ToolCallException when the call is a worker call of the project */
    public function requireNotWorker(Project $project): void
    {
        if ($this->callerRun->isWorker($project)) {
            throw new ToolCallException(self::REFUSAL);
        }
    }
}
