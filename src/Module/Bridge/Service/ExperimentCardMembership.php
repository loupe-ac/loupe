<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Tells whether the Cards tab of an experiment lists a card: a pin, a run or a fact of the experiment names it. */
final readonly class ExperimentCardMembership
{
    public function __construct(
        private ExperimentPinRepository $experimentPins,
        private WorkerRunRepository $workerRuns,
        private WorkerRunFactRepository $workerRunFacts,
        private CardReportSourceInterface $cardReports,
    ) {
    }

    public function experimentExists(Project $project, string $experiment): bool
    {
        return $this->workerRuns->experimentHasRun($project, $experiment)
            || [] !== $this->experimentPins->findOfExperiment($project, $experiment)
            || $this->workerRunFacts->experimentHasFact($project, $experiment);
    }

    /**
     * The id of the card with this number, when the experiment lists it. A run or a fact of the experiment
     * resolves the number first, so a card deleted from the board stays reachable.
     */
    public function resolve(Project $project, string $experiment, int $number): ?Uuid
    {
        $cardId = $this->workerRuns->findExperimentCardIdByNumber($project, $experiment, $number)
            ?? $this->workerRunFacts->findExperimentCardIdByNumber($project, $experiment, $number);
        if (null !== $cardId) {
            return $cardId;
        }

        $cardId = $this->cardReports->cardIdForNumber($project, $number);
        if (null === $cardId) {
            return null;
        }

        return $this->experimentPins->existsForCard($project, $experiment, $cardId)
            || $this->workerRuns->experimentHasCard($project, $experiment, $cardId)
            || $this->workerRunFacts->experimentHasCard($project, $experiment, $cardId) ? $cardId : null;
    }
}
