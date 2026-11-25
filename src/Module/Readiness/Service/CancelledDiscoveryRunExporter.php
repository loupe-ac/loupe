<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Readiness\Repository\DiscoveryRunRepository;

/** The discovery runs that the person cancelled, also in a project of another person. */
final readonly class CancelledDiscoveryRunExporter implements UserDataExporterInterface
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'cancelled_discovery_runs.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->discoveryRuns->findCancelledBy($user) as $run) {
            yield [
                'runId' => (string) $run->id,
                'project' => $run->project->name,
                'requestedAt' => $run->createdAt->format(\DateTimeInterface::ATOM),
                'cancelledAt' => $run->endedAt?->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
