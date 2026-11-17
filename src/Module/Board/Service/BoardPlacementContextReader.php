<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardPlacementContext;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Component\Uid\Uuid;

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

    /** @param list<Uuid>|null $cardIds the cards to place, so only their inbox items are read; null reads the project's */
    public function read(Project $project, ?array $cardIds = null): BoardPlacementContext
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
            $this->cards->findLaneEpicIds($project, $types->withLane()),
            null === $cardIds ? $this->openInboxItems->forProject($project) : $this->openInboxItems->forCards($project, $cardIds),
        );
    }
}
