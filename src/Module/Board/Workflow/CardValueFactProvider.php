<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardValueKeys;

final readonly class CardValueFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardValueRepository $cardValues,
        private CardValueKeys $keys,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return CardValueFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'card-value';
    }

    /** A row of a key the template does not declare, or a value of the wrong type, reads as unset. */
    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');
        $declared = [];
        foreach ($this->keys->forProject($stored->project->requireId()) as $key) {
            $declared[$key->key] = $key->type;
        }

        $values = [];
        if ([] !== $declared) {
            foreach ($this->cardValues->findForCard($stored) as $row) {
                if (isset($declared[$row->key]) && $declared[$row->key]->accepts($row->value)) {
                    $values[$row->key] = $row->value;
                }
            }
        }

        return new CardValueFacts($values);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof CardValueFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->values;
    }
}
