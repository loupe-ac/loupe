<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\ExperimentExclusionRepository;

final readonly class ExperimentExclusionExporter implements UserDataExporterInterface
{
    public function __construct(
        private ExperimentExclusionRepository $experimentExclusions,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'experiment_exclusions.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->experimentExclusions->findByOwner($user) as $exclusion) {
            yield [
                'project' => $exclusion->project->name,
                'cardId' => (string) $exclusion->cardId,
                'experiment' => $exclusion->experiment,
                'reason' => $exclusion->reason,
                'excludedAt' => $exclusion->excludedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
