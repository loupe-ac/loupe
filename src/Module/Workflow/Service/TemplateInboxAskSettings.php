<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\InboxAskSettings;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateMissing;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the `asks:` section from the stored template copy of the project. */
#[AsAlias(InboxAskSettings::class)]
final readonly class TemplateInboxAskSettings implements InboxAskSettings
{
    public function __construct(
        private ProjectTemplateCopy $templates,
    ) {
    }

    #[\Override]
    public function groupByCard(Uuid $projectId): bool
    {
        try {
            return $this->templates->forProject($projectId)->asks->groupByCard;
        } catch (TemplateMissing) {
            return true;
        }
    }
}
