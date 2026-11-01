<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\ProjectTemplateKeys;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the template key from the stored template copy of the project. */
#[AsAlias(ProjectTemplateKeys::class)]
final readonly class StoredProjectTemplateKeys implements ProjectTemplateKeys
{
    public function __construct(
        private TemplateSource $source,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId): ?string
    {
        try {
            return $this->source->forProject($projectId)->key;
        } catch (TemplateMissing) {
            return null;
        }
    }
}
