<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\SeedContext;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Service\Dev\DevArtifactSeeder;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Links the pricing prototype to the history card. The card page then lists an artifact and the artifact page lists a
 * card, while the column rules card and the archived sketch stay without a link as the empty twins of those pages.
 */
#[When('dev')]
#[When('test')]
final readonly class DevCardArtifactLinkSeeder implements DevSeederInterface
{
    public const string KEY = 'board-card-artifact-link';

    public function __construct(
        private EntityManagerInterface $em,
        private CardRepository $cards,
        private ArtifactRepository $artifacts,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevBoardSeeder::KEY, DevArtifactSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $card = $this->cards->find(DevBoardSeeder::cardId('history'));
        $artifact = $this->artifacts->find(DevArtifactSeeder::artifactId());
        if (!$card instanceof Card || !$artifact instanceof Artifact) {
            return;
        }

        $card->linkArtifact($artifact);
        $this->em->flush();
    }
}
