<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Service\WizardState;
use App\Module\Project\Workshop\WorkshopReadinessProviderInterface;
use App\Module\Project\Workshop\WorkshopReadinessRow;

/** What the machine step of the wizard shows: the project and the four rows of what Loupe sees. */
final readonly class ShowWizardMachineHandler
{
    public function __construct(
        private WizardState $wizardState,
        private WorkshopReadinessProviderInterface $readiness,
    ) {
    }

    public function __invoke(ShowWizardMachineCommand $command): ShowWizardMachineView
    {
        $completed = $this->wizardState->isCompleted($command->user);
        $project = $completed ? null : $this->wizardState->firstProject($command->user);
        $rows = null === $project ? [] : $this->readiness->wizardRows($project);
        $bridge = array_find($rows, static fn (WorkshopReadinessRow $row): bool => 'bridge' === $row->key);

        return new ShowWizardMachineView($completed, $project, $rows, null !== $bridge && $bridge->done);
    }
}
