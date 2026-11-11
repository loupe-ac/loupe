<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Turns card references into the cards of one project.
 *
 * A reference is a card number or a card id. An integer, or a string of digits,
 * is a number. Any other string must be a UUID. A reference that names no card
 * of the project is collected, and one DomainErrors names every one of them,
 * so a caller fixes the whole call at once. A card of another project reads as
 * unknown.
 */
final readonly class CardRefResolver
{
    public const string UNKNOWN = 'board.card.error.card_ref_unknown';

    /** The JSON schema of one card reference, a number or an id. */
    public const array SCHEMA = ['type' => ['integer', 'string']];

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /**
     * @param array<string, list<mixed>> $groups the references to resolve, by the field that carries them
     *
     * @return array<string, list<Card>> the cards of each field, in the order of its references
     *
     * @throws DomainErrors one entry for each field that holds a reference naming no card
     */
    public function resolve(Project $project, array $groups): array
    {
        $numbers = [];
        $ids = [];
        foreach ($groups as $references) {
            foreach ($references as $reference) {
                $number = self::numberOf($reference);
                if (null !== $number) {
                    $numbers[] = $number;
                } elseif (\is_string($reference) && Uuid::isValid(trim($reference))) {
                    $ids[] = Uuid::fromString(trim($reference));
                }
            }
        }

        $byNumber = $this->cards->findByProjectAndNumbers($project, $numbers);
        $byId = [];
        foreach ($this->cards->findByIdsInProject($project, $ids) as $card) {
            $byId[(string) $card->id] = $card;
        }

        $resolved = [];
        $errors = [];
        $parameters = [];
        foreach ($groups as $field => $references) {
            $resolved[$field] = [];
            $unknown = [];
            foreach ($references as $reference) {
                $number = self::numberOf($reference);
                $card = null !== $number
                    ? ($byNumber[$number] ?? null)
                    : (\is_string($reference) && Uuid::isValid(trim($reference)) ? ($byId[Uuid::fromString(trim($reference))->toRfc4122()] ?? null) : null);
                if (null === $card) {
                    $unknown[] = self::label($reference);
                } else {
                    $resolved[$field][] = $card;
                }
            }
            if ([] !== $unknown) {
                $errors[$field] = self::UNKNOWN;
                $parameters[$field] = ['%cards%' => implode(', ', $unknown)];
            }
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors, $parameters);
        }

        return $resolved;
    }

    private static function numberOf(mixed $reference): ?int
    {
        if (\is_int($reference)) {
            return $reference >= 1 && $reference <= 999_999_999 ? $reference : null;
        }
        if (\is_string($reference) && 1 === preg_match('/^[0-9]{1,9}$/', trim($reference))) {
            $number = (int) trim($reference);

            return $number >= 1 ? $number : null;
        }

        return null;
    }

    private static function label(mixed $reference): string
    {
        return \is_int($reference) || \is_string($reference) ? (string) $reference : get_debug_type($reference);
    }
}
