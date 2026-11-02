<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardValueKeys;
use App\Module\Workflow\Contract\TemplateValueKey;

/** Sets the value of a card for a key of its workflow template, and records the change in its history. */
final readonly class CardValueWriter
{
    public const string UNDECLARED_KEY = 'board.card.error.value_key_undeclared';
    public const string UNDECLARED_KEY_NONE = 'board.card.error.value_key_undeclared_none';
    public const string WRONG_TYPE_PREFIX = 'board.card.error.value_type_';

    public function __construct(
        private CardValueKeys $keys,
        private CardValueRepository $cardValues,
        private CardEventRepository $cardEvents,
        private CardEventActor $eventActor,
    ) {
    }

    /**
     * Checks every entry and collects every failure, so a caller can refuse the whole set before it writes any.
     *
     * @param array<array-key, mixed> $values key => value
     */
    public function refusal(Card $card, array $values): ?DomainErrors
    {
        $declared = $this->declared($card);

        $errors = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $field = 'values.'.$key;
            $template = $declared[$key] ?? null;
            if (null === $template) {
                $errors[$field] = [] === $declared ? self::UNDECLARED_KEY_NONE : self::UNDECLARED_KEY;
                $parameters[$field] = ['%key%' => $key, '%declared%' => implode(', ', array_keys($declared))];
            } elseif (!$template->type->accepts($value)) {
                $errors[$field] = self::WRONG_TYPE_PREFIX.$template->type->value;
            }
        }

        return [] === $errors ? null : new DomainErrors($errors, $parameters);
    }

    /**
     * Call it inside the transaction that flushes the card, and under the project lock.
     * A value the card already holds changes nothing and writes no row.
     *
     * @return bool whether the card holds another value now
     *
     * @throws DomainErrors when the template declares no such key, or the value is of another type
     */
    public function set(Card $card, string $key, mixed $value, Actor $actor): bool
    {
        $template = $this->declared($card)[$key] ?? null;
        if (null === $template || !$template->type->accepts($value)) {
            throw $this->refusal($card, [$key => $value]) ?? new \LogicException('A refused value has errors.');
        }

        $row = $this->cardValues->findOneForCardFresh($card, $key);
        $previous = null !== $row && $template->type->accepts($row->value) ? $row->value : null;
        if (null !== $previous && $previous === $value) {
            return false;
        }

        $user = $this->eventActor->userFor($actor);
        if (null === $row) {
            $this->cardValues->store(new CardValue($card, $key, $value, $actor, $user));
        } else {
            $row->value = $value;
            $row->writerKind = $actor;
            $row->writerUser = $user;
            $row->updatedAt = new \DateTimeImmutable();
        }
        $this->cardEvents->record($card, CardEventKind::ValueChanged, $actor, $user, [
            'key' => $key,
            'value' => $value,
            'previous' => $previous,
        ], null, $this->eventActor->apiKeyFor($actor));

        return true;
    }

    /** @return array<string, TemplateValueKey> */
    private function declared(Card $card): array
    {
        $declared = [];
        foreach ($this->keys->forProject($card->project->requireId()) as $key) {
            $declared[$key->key] = $key;
        }

        return $declared;
    }
}
