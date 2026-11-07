<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\ColumnStarts;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Reads the hint of a column from the stored template copy of the project. The translation uses the default locale, because the caller carries no locale. */
#[AsAlias(ColumnStarts::class)]
final readonly class WorkflowColumnStarts implements ColumnStarts
{
    private const string REQUEST_ACTION = 'request';

    public function __construct(
        private ProjectTemplates $templates,
        private WorkflowAutomation $automation,
        private Actions $actions,
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
        $slotKey = $this->slotKeyOf($projectId, $column);
        $hint = null === $slotKey ? null : $this->templates->of($projectId)->slot($slotKey)?->hint;

        return null === $hint ? null : $this->translator->trans($hint, locale: $this->defaultLocale);
    }

    #[\Override]
    public function startsWork(Uuid $projectId, ColumnRef $column): bool
    {
        $slotKey = $this->slotKeyOf($projectId, $column);
        if (null === $slotKey) {
            return false;
        }

        return array_any(
            $this->templates->of($projectId)->rulesFor($slotKey),
            fn (Rule $rule): bool => $rule->slot === $slotKey && array_any($rule->then, fn (ActionCall $call): bool => self::REQUEST_ACTION === $call->key && null !== $this->actions->get($call->key)->workKind($call->params)),
        );
    }

    private function slotKeyOf(Uuid $projectId, ColumnRef $column): ?string
    {
        if ($column->backlog || $column->terminal) {
            return null;
        }
        $project = $this->projects->find($projectId);
        if (!$project instanceof Project || !$this->automation->runsFor($project)) {
            return null;
        }

        return $this->workflowSlotLinks->findSlotKeyForColumnId($project, $column->id);
    }
}
