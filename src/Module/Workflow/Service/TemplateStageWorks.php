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
    public function ofCards(Uuid $projectId, array $cardIds): array
    {
        $project = $this->projects->find($projectId);
        if (null === $project) {
            return [];
        }

        $works = [];
        $worksByColumn = [];
        foreach ($this->cards->findManyInProject($projectId, $cardIds) as $card) {
            if ($card->column->backlog || $card->column->terminal) {
                continue;
            }
            $column = $card->column->id->toRfc4122();
            if (!\array_key_exists($column, $worksByColumn)) {
                $slotKey = $this->workflowSlotLinks->findSlotKeyForColumnId($project, $card->column->id);
                $worksByColumn[$column] = null === $slotKey ? null : $this->templates->of($projectId)->slot($slotKey)?->stageWork;
            }
            if (null !== $worksByColumn[$column]) {
                $works[$card->id->toRfc4122()] = $worksByColumn[$column];
            }
        }

        return $works;
    }
}
