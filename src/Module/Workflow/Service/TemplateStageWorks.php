<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\StageWorks;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the stage work of the slot of a card from the stored template copy of the project. */
#[AsAlias(StageWorks::class)]
final readonly class TemplateStageWorks implements StageWorks
{
    public function __construct(
        private ProjectTemplates $templates,
        private CardDirectory $cards,
        private ProjectRepository $projects,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId, Uuid $cardId): ?string
    {
        $card = $this->cards->findInProject($projectId, $cardId);
        if (null === $card || $card->column->backlog || $card->column->terminal) {
            return null;
        }
        $project = $this->projects->find($projectId);
        $slotKey = null === $project ? null : $this->workflowSlotLinks->findSlotKeyForColumnId($project, $card->column->id);

        return null === $slotKey ? null : $this->templates->of($projectId)->slot($slotKey)?->stageWork;
    }
}
