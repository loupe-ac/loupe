<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the outcomes and metrics from the stored template copy. A project bound to no template gets those of the Simple template. */
#[AsAlias(TemplateMetrics::class)]
final readonly class TemplateMetricCatalog implements TemplateMetrics
{
    private const string UNBOUND_TEMPLATE = 'simple';

    public function __construct(
        private TemplateSource $templates,
        private ShippedTemplates $shippedTemplates,
        private TemplateParser $parser,
        private ProjectRepository $projects,
        private BoardColumns $boardColumns,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): TemplateMetricSet
    {
        $template = $this->template($projectId);
        if ([] === $template->metrics) {
            return new TemplateMetricSet([], $template->outcomes);
        }

        return new TemplateMetricSet($template->metrics, $template->outcomes, $this->slotColumns($projectId, $template));
    }

    private function template(Uuid $projectId): Template
    {
        try {
            return $this->templates->forProject($projectId);
        } catch (TemplateMissing) {
            return $this->parser->parse($this->shippedTemplates->source(self::UNBOUND_TEMPLATE));
        }
    }

    /** @return array<string, ?Uuid> */
    private function slotColumns(Uuid $projectId, Template $template): array
    {
        $project = $this->projects->find($projectId) ?? throw new \LogicException('A project of a card has a row.');
        $columns = $this->boardColumns->forProject($projectId);
        $linked = $this->workflowSlotLinks->findColumnsBySlot($project);
        $map = [
            SlotKeys::BACKLOG => array_find($columns, static fn ($view): bool => $view->backlog)?->id,
            SlotKeys::TERMINAL => array_find($columns, static fn ($view): bool => $view->terminal)?->id,
        ];
        foreach ($template->slots as $slot) {
            $map[$slot->key] = ($linked[$slot->key] ?? null)?->id;
        }

        return $map;
    }
}
