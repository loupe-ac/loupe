<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Service\ArtifactLinkedCardsInterface;
use App\Module\Artifact\Service\LinkedCard;
use App\Module\Artifact\Service\LinkedCardTally;
use App\Module\Board\Repository\CardArtifactRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ArtifactLinkedCardsInterface::class)]
final readonly class BoardArtifactLinkedCards implements ArtifactLinkedCardsInterface
{
    public function __construct(
        private CardArtifactRepository $cardArtifacts,
    ) {
    }

    #[\Override]
    public function tallyFor(array $artifacts): array
    {
        return array_map(
            static fn (array $row): LinkedCardTally => new LinkedCardTally($row['count'], $row['firstNumber']),
            $this->cardArtifacts->tallyByArtifacts($artifacts),
        );
    }

    #[\Override]
    public function cardsOf(Artifact $artifact): array
    {
        return array_map(
            static fn ($link): LinkedCard => new LinkedCard((string) $link->card->id, $link->card->number, $link->card->title),
            $this->cardArtifacts->findByArtifact($artifact),
        );
    }
}
