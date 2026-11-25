<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\AskLabelRule;
use App\Module\Workflow\Contract\InboxAskSettings;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateAsks;
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

    #[\Override]
    public function labelRule(Uuid $projectId, string $label): AskLabelRule
    {
        try {
            $asks = $this->templates->forProject($projectId)->asks;
        } catch (TemplateMissing) {
            $asks = new TemplateAsks();
        }

        return new AskLabelRule(
            $asks->modeOf($label),
            $asks->defaultHours,
            $asks->fields,
        );
    }
}
