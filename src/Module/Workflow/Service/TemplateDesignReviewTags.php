<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\DesignReviewTags;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the design review tags from the stored template copy. A project bound to no template gets the tags of the Simple template. */
#[AsAlias(DesignReviewTags::class)]
final class TemplateDesignReviewTags implements DesignReviewTags, ResetInterface
{
    private const string UNBOUND_TEMPLATE = 'simple';

    /** @var array<string, list<string>> */
    private array $tags = [];

    public function __construct(
        private readonly TemplateSource $source,
        private readonly ShippedTemplates $shippedTemplates,
        private readonly TemplateParser $parser,
        private readonly ProjectRepository $projects,
        private readonly WorkflowSlotLinkRepository $workflowSlotLinks,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId): array
    {
        return $this->tags[$projectId->toRfc4122()] ??= $this->template($projectId)->designReviewTags();
    }

    #[\Override]
    public function reviewedInColumn(Uuid $projectId, string $columnId): array
    {
        if (!Uuid::isValid($columnId)) {
            return [];
        }
        $project = $this->projects->find($projectId);
        $slot = null === $project ? null : $this->workflowSlotLinks->findSlotKeyForColumnId($project, Uuid::fromString($columnId));

        return null === $slot ? [] : $this->template($projectId)->designReviewTagsIn($slot);
    }

    #[\Override]
    public function reset(): void
    {
        $this->tags = [];
    }

    private function template(Uuid $projectId): Template
    {
        try {
            return $this->source->forProject($projectId);
        } catch (TemplateMissing) {
            return $this->parser->parse($this->shippedTemplates->source(self::UNBOUND_TEMPLATE));
        }
    }
}
