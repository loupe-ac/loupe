<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Board\Entity\Card;

/** The rows of the open list that link one card, or the rows that link none when the card is null. */
final readonly class InboxGroup
{
    public \DateTimeImmutable $newest;

    /** @param non-empty-list<InboxRow> $rows */
    public function __construct(
        public ?Card $card,
        public array $rows,
    ) {
        $this->newest = array_reduce(
            $rows,
            static fn (?\DateTimeImmutable $newest, InboxRow $row): \DateTimeImmutable => null === $newest || $row->createdAt() > $newest ? $row->createdAt() : $newest,
        ) ?? throw new \LogicException('A group holds at least one row.');
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
     * One group for each card that a row links, a row with two cards in both, and the group of no card last. The newest group comes first.
     *
     * @param list<InboxRow> $rows
     *
     * @return list<InboxGroup>
     */
    public static function build(array $rows): array
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

        $groups = [];
        foreach ($byCard as $key => $held) {
            $groups[] = new self($cards[$key], $held);
        }
        usort($groups, static fn (self $a, self $b): int => [$b->newest, $a->card?->number] <=> [$a->newest, $b->card?->number]);
        if ([] !== $loose) {
            $groups[] = new self(null, $loose);
        }

        return $groups;
    }
}
