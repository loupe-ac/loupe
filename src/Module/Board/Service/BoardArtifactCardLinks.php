<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Service\ArtifactCardLinksInterface;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardArtifactsChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ArtifactCardLinksInterface::class)]
final readonly class BoardArtifactCardLinks implements ArtifactCardLinksInterface
{
    public function __construct(
        private CardRepository $cards,
        private EventDispatcherInterface $events,
    ) {
    }

    #[\Override]
    public function cardExists(Project $project, string $card): bool
    {
        return null !== $this->find($project, $card);
    }

    #[\Override]
    public function link(Project $project, string $card, Artifact $artifact): void
    {
        $found = $this->find($project, $card);
        if (null === $found) {
            return;
        }
        $found->linkArtifact($artifact);
        $this->events->dispatch(new CardArtifactsChanged($project->requireId(), $found->id ?? throw new \LogicException('A stored card has an id.')));
    }

    /** The card number counts from 1 inside the project, and anything else is read as an id. */
    private function find(Project $project, string $card): ?Card
    {
        $card = trim($card);
        if (1 === preg_match('/^[1-9][0-9]{0,8}$/', $card)) {
            return $this->cards->findOneByProjectAndNumber($project, (int) $card);
        }

        return $this->cards->findOneByIdAndProjectId($card, (string) $project->id);
    }
}
