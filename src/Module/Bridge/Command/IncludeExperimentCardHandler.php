<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Event\ExperimentSetupChanged;
use App\Module\Bridge\Repository\ExperimentExclusionRepository;
use App\Module\Bridge\Service\ExperimentCardMembership;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class IncludeExperimentCardHandler
{
    public function __construct(
        private ExperimentCardMembership $membership,
        private ExperimentExclusionRepository $experimentExclusions,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * A card with no exclusion is already included, so the call succeeds.
     *
     * @return Uuid the id of the included card
     *
     * @throws DomainErrors when the project has no such experiment, or the experiment does not list the card
     */
    public function __invoke(IncludeExperimentCardCommand $command): Uuid
    {
        $project = $command->project;
        if (!$this->membership->experimentExists($project, $command->experiment)) {
            throw new DomainErrors(['experiment' => 'bridge.experiments.exclusion.error.unknown_experiment']);
        }
        $cardId = $this->membership->resolve($project, $command->experiment, $command->number)
            ?? throw new DomainErrors(['card' => 'bridge.experiments.exclusion.error.card_not_in_experiment']);

        $this->experimentExclusions->deleteOfCard($project, $command->experiment, $cardId);

        $this->events->dispatch(new ExperimentSetupChanged((string) $project->requireId(), $command->experiment));
        $this->auditor->record(
            'experiment.card_included',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'experiment' => $command->experiment, 'cardId' => (string) $cardId],
            new AuditSubject('experiment', $command->experiment),
        );

        return $cardId;
    }
}
