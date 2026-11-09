<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class CheckFormulaCommand
{
    public function __construct(
        public Project $project,
        public string $rows,
        public string $formula,
        public string $format,
    ) {
    }
}
