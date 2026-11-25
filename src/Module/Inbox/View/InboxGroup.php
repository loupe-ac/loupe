<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Board\Entity\Card;

/**
 * The rows of the open list that link one card, or the rows that link none when the card is null.
 * A card with decisions the viewer has not seen carries its digest, and a group of such a card with no row holds only that.
 */
final readonly class InboxGroup
{
    public \DateTimeImmutable $newest;

    /** @param list<InboxRow> $rows */
    public function __construct(
        public ?Card $card,
        public array $rows,
        public ?InboxDigest $digest = null,
    ) {
        $newest = array_reduce(
            $rows,
            static fn (?\DateTimeImmutable $newest, InboxRow $row): \DateTimeImmutable => null === $newest || $row->createdAt() > $newest ? $row->createdAt() : $newest,
        );
        if (null !== $digest && (null === $newest || $digest->newest > $newest)) {
            $newest = $digest->newest;
        }
        $this->newest = $newest ?? throw new \LogicException('A group holds a row or a digest.');
    }

    public function key(): string
    {
        return null === $this->card ? 'none' : (string) $this->card->id;
    }

    /** The first row that waits on the owner, which makes the group worth a flag. */
    public function blocking(): bool
    {
        return null !== array_find($this->rows, static fn (InboxRow $row): bool => $row->blocking);
    }

    /**
     * One group for each card that a row links or that has a digest, a row with two cards in both, and the group of no card last. The newest group comes first.
     *
     * @param list<InboxRow>    $rows
     * @param list<InboxDigest> $digests
     *
     * @return list<InboxGroup>
     */
    public static function build(array $rows, array $digests = []): array
    {
        $cards = [];
        $byCard = [];
        $loose = [];
        foreach ($rows as $row) {
            if ([] === $row->cards) {
                $loose[] = $row;
            }
            foreach ($row->cards as $card) {
                $key = (string) $card->id;
                $cards[$key] = $card;
                $byCard[$key][] = $row;
            }
        }

        $digestOf = [];
        foreach ($digests as $digest) {
            $digestOf[(string) $digest->card->id] = $digest;
            $cards[(string) $digest->card->id] = $digest->card;
            $byCard[(string) $digest->card->id] ??= [];
        }

        $groups = [];
        foreach ($byCard as $key => $held) {
            $groups[] = new self($cards[$key], $held, $digestOf[$key] ?? null);
        }
        usort($groups, static fn (self $a, self $b): int => [$b->newest, $a->card?->number] <=> [$a->newest, $b->card?->number]);
        if ([] !== $loose) {
            $groups[] = new self(null, $loose);
        }

        return $groups;
    }
}
