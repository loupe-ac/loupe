<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HoldFactProvider implements FactProvider
{
    public function __construct(
        private CardHoldRepository $cardHolds,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return HoldFacts::class;
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

        return new HoldFacts($this->cardHolds->findOneOfCard($project, $card->id)?->heldAt);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof HoldFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return null !== $facts->heldAt;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
