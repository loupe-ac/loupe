<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Service\ArtifactCardLinksInterface;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ArtifactCardLinksInterface::class)]
final readonly class BoardArtifactCardLinks implements ArtifactCardLinksInterface
{
    public function __construct(
        private CardRepository $cards,
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
        $this->find($project, $card)?->linkArtifact($artifact);
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
