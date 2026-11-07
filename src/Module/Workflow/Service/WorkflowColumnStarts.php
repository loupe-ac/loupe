<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\ColumnStarts;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Reads the hint of a column from the stored template copy of the project. The translation uses the default locale, because the caller carries no locale. */
#[AsAlias(ColumnStarts::class)]
final readonly class WorkflowColumnStarts implements ColumnStarts
{
    public function __construct(
        private ProjectTemplates $templates,
        private WorkflowAutomation $automation,
        private ProjectRepository $projects,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $defaultLocale,
    ) {
    }

    #[\Override]
    public function hint(Uuid $projectId, ColumnRef $column): ?string
    {
        if ($column->backlog || $column->terminal) {
            return null;
        }
        $project = $this->projects->find($projectId);
        if (null === $project || !$this->automation->runsFor($project)) {
            return null;
        }
        $slotKey = $this->workflowSlotLinks->findSlotKeyForColumnId($project, $column->id);
        $hint = null === $slotKey ? null : $this->templates->of($projectId)->slot($slotKey)?->hint;

        return null === $hint ? null : $this->translator->trans($hint, locale: $this->defaultLocale);
    }
}
