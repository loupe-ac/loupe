<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentArchiveState;
use App\Module\Bridge\Experiment\ExperimentListPosition;
use App\Module\Bridge\Experiment\ExperimentSummary;
use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;

final readonly class ListExperimentsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private ExperimentArchiveRepository $experimentArchives,
    ) {
    }

    public function __invoke(ListExperimentsCommand $command): ListExperimentsView
    {
        /** @var array<string, array<string, true>> $cards experiment => card id set */
        $cards = [];
        /** @var array<string, \DateTimeImmutable> $lastRunAt */
        $lastRunAt = [];
        foreach ($this->workerRuns->findExperimentCardRows($command->project) as $row) {
            $cards[$row['experiment']][$row['cardId']] = true;
            if (!isset($lastRunAt[$row['experiment']]) || $row['lastRunAt'] > $lastRunAt[$row['experiment']]) {
                $lastRunAt[$row['experiment']] = $row['lastRunAt'];
            }
        }
        foreach ($this->experimentPins->findExperimentCardRows($command->project) as $row) {
            $cards[$row['experiment']][$row['cardId']] = true;
        }

        $archives = $this->experimentArchives->findByProject($command->project);
        $runIds = [] === $archives ? [] : $this->workerRuns->findExperimentRunIdsFromArchive($command->project);

        $experiments = [];
        $archivedCount = 0;
        foreach ($cards as $name => $ids) {
            $archived = ExperimentArchiveState::isArchived($archives[$name] ?? null, $runIds[$name] ?? []);
            if ($archived) {
                ++$archivedCount;
            }
            if ($archived === $command->archived) {
                $experiments[] = new ExperimentSummary((string) $name, \count($ids), $lastRunAt[$name] ?? null, $archived);
            }
        }
        usort($experiments, static fn (ExperimentSummary $a, ExperimentSummary $b): int => [null === $a->lastRunAt, $b->lastRunAt, $a->name] <=> [null === $b->lastRunAt, $a->lastRunAt, $b->name]);

        $hasMore = false;
        if (null !== $command->after) {
            $after = $command->after;
            $experiments = array_values(array_filter($experiments, static fn (ExperimentSummary $summary): bool => self::follows($summary, $after)));
        }
        if (null !== $command->limit && \count($experiments) > $command->limit) {
            $hasMore = true;
            $experiments = \array_slice($experiments, 0, $command->limit);
        }

        return new ListExperimentsView($experiments, $archivedCount, $hasMore);
    }

    /** True when the experiment comes after the position in the order of the list. */
    private static function follows(ExperimentSummary $summary, ExperimentListPosition $after): bool
    {
        if (null === $after->lastRunAt) {
            return null === $summary->lastRunAt && $summary->name > $after->name;
        }
        if (null === $summary->lastRunAt) {
            return true;
        }

        return [$after->lastRunAt, $summary->name] > [$summary->lastRunAt, $after->name];
    }
}
