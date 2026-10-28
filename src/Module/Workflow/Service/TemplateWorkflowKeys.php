<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\WorkflowKeys;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateMissing;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the key from the stored template copy of the project. */
#[AsAlias(WorkflowKeys::class)]
final readonly class TemplateWorkflowKeys implements WorkflowKeys
{
    public function __construct(
        private ProjectTemplateCopy $templates,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): ?string
    {
        try {
            return $this->templates->forProject($projectId)->key;
        } catch (TemplateMissing) {
            return null;
        }
    }
}
