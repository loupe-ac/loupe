<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntryBuilder;
use App\Outbox\Repository\OutboxEventRepository;

final readonly class ListActivityRowsHandler
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private ActivityEntryBuilder $entries,
    ) {
    }

    public function __invoke(ListActivityRowsCommand $command): ListActivityRowsView
    {
        $listQuery = $command->listQuery;
        $ids = \array_slice(array_values(array_unique($command->ids)), 0, ListActivityPageHandler::MAX_PER_PAGE);
        $events = $this->outboxEvents->findByIdsForProject($command->project, $ids, $listQuery->search, $listQuery->family);
        $kept = array_map(static fn ($event): string => (string) $event->id, $events);

        return new ListActivityRowsView(
            entries: $this->entries->entriesFor($command->project, $events),
            removedIds: array_values(array_diff($ids, $kept)),
            newerCount: $this->outboxEvents->countAboveForProject($command->project, $command->since, $listQuery->search, $listQuery->family),
            filteredTotal: $this->outboxEvents->countAboveForProject($command->project, 0, $listQuery->search, $listQuery->family),
        );
    }
}
