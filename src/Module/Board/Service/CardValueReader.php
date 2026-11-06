<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\View\CardValueView;
use App\Module\Workflow\Contract\CardValueKeys;

/** Reads the keys the workflow template declares for a card, each with the value the card holds. */
final readonly class CardValueReader
{
    public function __construct(
        private CardValueKeys $keys,
        private CardValueRepository $cardValues,
    ) {
    }

    /**
     * A stored row of a key the template no longer declares is left out.
     *
     * @return list<CardValueView> one for each declared key, in template order
     */
    public function forCard(Card $card): array
    {
        if ([] === $this->keys->forProject($card->project->requireId())) {
            return [];
        }

        return $this->viewsOf($card, $this->cardValues->findForCard($card));
    }

    /**
     * The same views as forCard(), for many cards with one read of the values.
     *
     * @param list<Card> $cards
     *
     * @return array<string, list<CardValueView>> keyed by card id
     */
    public function forCards(array $cards): array
    {
        $declaring = array_values(array_filter(
            $cards,
            fn (Card $card): bool => [] !== $this->keys->forProject($card->project->requireId()),
        ));
        $rowsByCard = $this->cardValues->findForCards($declaring);

        $views = [];
        foreach ($cards as $card) {
            $views[(string) $card->id] = [] === $this->keys->forProject($card->project->requireId())
                ? []
                : $this->viewsOf($card, $rowsByCard[(string) $card->id] ?? []);
        }

        return $views;
    }

    /**
     * @param list<CardValue> $stored
     *
     * @return list<CardValueView>
     */
    private function viewsOf(Card $card, array $stored): array
    {
        $declared = $this->keys->forProject($card->project->requireId());
        $rows = [];
        foreach ($stored as $row) {
            $rows[$row->key] = $row;
        }

        $views = [];
        foreach ($declared as $key) {
            $row = $rows[$key->key] ?? null;
            $held = null !== $row && $key->type->accepts($row->value);
            $views[] = new CardValueView(
                $key->key,
                $key->type,
                $key->label,
                $key->caption,
                $key->help,
                $key->history,
                $held ? $row->value : null,
                $held ? $row->writerKind : null,
                $held ? $row->writerUser : null,
                $held ? $row->updatedAt : null,
                $key->visibility,
            );
        }

        return $views;
    }
}
