<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopReadinessRow;

final readonly class ShowWizardMachineView
{
    public function __construct(
        public bool $completed,
        public ?Project $project,
        /** @var list<WorkshopReadinessRow> */
        public array $rows,
        /** True once the bridge row is done, which is when the wizard may go on to the Workshop. */
        public bool $ready,
    ) {
    }
}
