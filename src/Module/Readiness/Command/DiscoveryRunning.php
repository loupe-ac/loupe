<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use Symfony\Component\Uid\Uuid;

/** Thrown when a discovery starts while a run of the project is still requested or revising. */
final class DiscoveryRunning extends \DomainException
{
    public const string MESSAGE = 'readiness.discovery.error.running';

    public function __construct(
        public readonly Uuid $runId,
    ) {
        parent::__construct(\sprintf('Discovery already runs, in run %s.', $runId));
    }
}
