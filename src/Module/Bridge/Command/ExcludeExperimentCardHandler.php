<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Repository\ExperimentExclusionRepository;
use App\Module\Bridge\Service\ExperimentCardMembership;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ExcludeExperimentCardHandler
{
    public function __construct(
        private ExperimentCardMembership $membership,
        private ExperimentExclusionRepository $experimentExclusions,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    /**
     * A second call replaces the reason, the author and the time.
     *
     * @return Uuid the id of the excluded card
     *
     * @throws DomainErrors when the project has no such experiment, or the experiment does not list the card
     */
    public function __invoke(ExcludeExperimentCardCommand $command): Uuid
    {
        $project = $command->project;
        if (!$this->membership->experimentExists($project, $command->experiment)) {
            throw new DomainErrors(['experiment' => 'bridge.experiments.exclusion.error.unknown_experiment']);
        }
        $cardId = $this->membership->resolve($project, $command->experiment, $command->number)
            ?? throw new DomainErrors(['card' => 'bridge.experiments.exclusion.error.card_not_in_experiment']);

        $this->experimentExclusions->upsert($project, $command->experiment, $cardId, $command->reason, $command->user, $this->clock->now());

        $this->auditor->record(
            'experiment.card_excluded',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'experiment' => $command->experiment, 'cardId' => (string) $cardId],
            new AuditSubject('experiment', $command->experiment),
        );

        return $cardId;
    }
}
