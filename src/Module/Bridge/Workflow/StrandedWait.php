<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use Symfony\Component\Uid\Uuid;

/** A wait that ended while its worker run was parked, and that no run resumed yet. */
final readonly class StrandedWait
{
    public function __construct(
        public Uuid $id,
        /** Whether the bridge that holds the session has stopped sending its heartbeat. */
        public bool $bridgeQuiet,
        /** The first instant at which a bridge that is not quiet yet reads quiet. */
        public ?\DateTimeImmutable $quietAt,
    ) {
    }
}
