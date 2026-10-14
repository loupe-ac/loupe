<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\WorkTimeouts;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the work timeout from the stored template copy of the project. */
#[AsAlias(WorkTimeouts::class)]
final readonly class TemplateWorkTimeouts implements WorkTimeouts
{
    public function __construct(
        private ProjectTemplates $templates,
    ) {
    }

    #[\Override]
    public function minutes(Uuid $projectId): int
    {
        return max(1, $this->templates->of($projectId)->workTimeoutMinutes);
    }
}
