<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;

/** A card on the board that links to an artifact. */
trait LinkedCardScenario
{
    use BoardColumnFixtures;

    private function linkedCard(Project $project, Artifact $artifact, string $title, int $number): Card
    {
        if ([] === $this->seededColumns) {
            $this->seedColumns($project);
        }
        $card = new Card($project, $this->column($project, 'backlog'), $title, 'Body', $number);
        $card->linkArtifact($artifact);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }
}
