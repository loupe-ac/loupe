<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Bridge\Entity\BridgeCommand;
use Symfony\Component\Uid\Uuid;

/**
 * A bridge command left the pending state: the bridge acknowledged or refused it, a person cancelled it, or
 * it expired. Dispatched after the commit, with ids only.
 */
final readonly class BridgeCommandSettled
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $commandId,
        /** Null for a command about an account, which names no run. */
        public ?Uuid $workerRunId,
    ) {
    }

    public static function of(BridgeCommand $command): self
    {
        return new self(
            $command->project->id ?? throw new \LogicException('A stored project has an id.'),
            $command->id ?? throw new \LogicException('A stored command has an id.'),
            null === $command->workerRun ? null : ($command->workerRun->id ?? throw new \LogicException('A stored run has an id.')),
        );
    }
}
