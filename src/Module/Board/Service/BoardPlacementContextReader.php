<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardPlacementContext;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardTypeCatalog;

/** Reads the board-wide values once, so one request can place many cards. */
final readonly class BoardPlacementContextReader
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardRepository $cards,
        private BoardAutomation $automation,
        private CardTypeCatalog $catalog,
        private CardOpenInboxItems $openInboxItems,
    ) {
    }

    public function read(Project $project): BoardPlacementContext
    {
        $windowStart = BoardColumnCards::windowStart($this->automation->settingsOf($project)->terminalWindowDays);
        $columns = [];
        $backlog = null;
        foreach ($this->boardColumns->findForProject($project) as $boardColumn) {
            if ($boardColumn->backlog) {
                $backlog = $boardColumn;
            } else {
                $columns[] = $boardColumn;
            }
        }
        $backlog ??= throw new \LogicException('Every board has a Backlog.');

        $stored = $this->columnCards->counts($project, $windowStart);
        $counts = [(string) $backlog->id => $stored[(string) $backlog->id]['shown'] ?? 0];
        $terminalTotals = [];
        foreach ($columns as $boardColumn) {
            $id = (string) $boardColumn->id;
            $counts[$id] = $stored[$id]['shown'] ?? 0;
            if ($boardColumn->terminal) {
                $terminalTotals[$id] = $stored[$id]['total'] ?? 0;
            }
        }

        $types = $this->catalog->forProject($project->requireId());

        return new BoardPlacementContext(
            $project,
            $backlog,
            $columns,
            $windowStart,
            $counts,
            $terminalTotals,
            $types,
            array_map(
                static fn (Card $epic): string => (string) $epic->id,
                $this->cards->findLaneEpics($project, $types->withLane()),
            ),
            $this->openInboxItems->forProject($project),
        );
    }
}
