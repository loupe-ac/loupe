<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\WidgetCardNotFound;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;

/** Finds a card of the project that the widget raised, and no other. */
final readonly class WidgetCardFinder
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /** @throws WidgetCardNotFound */
    public function find(Project $project, string $cardId): Card
    {
        $card = $this->cards->findOneByIdAndProjectId($cardId, (string) $project->id);
        if (null === $card || CardSourceKind::Widget !== $card->source->kind) {
            throw new WidgetCardNotFound();
        }

        return $card;
    }
}
