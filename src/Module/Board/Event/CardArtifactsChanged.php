<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use Symfony\Component\Uid\Uuid;

/** The artifacts linked to a card changed. Its children read them too, so the workflow checks the card and each child. */
final readonly class CardArtifactsChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
    ) {
    }
}
