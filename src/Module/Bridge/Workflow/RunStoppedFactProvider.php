<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Service\CardRunWarnings;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RunStoppedFactProvider implements FactProvider
{
    public function __construct(
        private CardRunWarnings $warnings,
        private CardDirectory $cards,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return RunStoppedFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $project = $this->em->getReference(Project::class, $card->projectId) ?? throw new \LogicException('The project is stored.');
        $warning = $this->warnings->forCard($project, $card->id);

        return new RunStoppedFacts($warning, null === $warning ? null : $this->cards->newestMoveAt($card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof RunStoppedFacts || throw new \LogicException('The provider fingerprints its own facts.');
        $stopped = $facts->stopped();

        return null === $stopped ? null : [$stopped->runId, $stopped->state->value];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
