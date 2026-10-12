<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Project\Entity\Project;

/** Artifact may not import Board, so Board answers this for a push that names a card. */
interface ArtifactCardLinksInterface
{
    /** Whether the project has a card with this id or number. */
    public function cardExists(Project $project, string $card): bool;

    /** Links the artifact to the card. Linking twice changes nothing. */
    public function link(Project $project, string $card, Artifact $artifact): void;
}
