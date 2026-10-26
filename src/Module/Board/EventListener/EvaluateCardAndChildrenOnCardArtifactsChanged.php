<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardArtifactsChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** An artifact of a card reaches the card and each of its children, so each of them needs a new check. */
#[AsEventListener]
final readonly class EvaluateCardAndChildrenOnCardArtifactsChanged
{
    public function __construct(
        private CardEvaluations $trigger,
        private CardRepository $cards,
    ) {
    }

    public function __invoke(CardArtifactsChanged $event): void
    {
        $card = $this->cards->find($event->cardId);
        $children = null === $card ? [] : array_map(static fn (Card $child) => $child->id, $this->cards->findChildren($card));
        $this->trigger->forCards(array_values(array_filter([$event->cardId, ...$children])));
    }
}
