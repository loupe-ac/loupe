<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Entity\Artifact;

/** Artifact may not import Board, so Board answers which cards link to an artifact. */
interface ArtifactLinkedCardsInterface
{
    /**
     * @param list<Artifact> $artifacts
     *
     * @return array<string, int> keyed by artifact id; an artifact with no link is absent
     */
    public function countFor(array $artifacts): array;

    /** @return list<LinkedCard> in card number order */
    public function cardsOf(Artifact $artifact): array;
}
