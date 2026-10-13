<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/**
 * How one card reads its link to another. A row stores only `RelatesTo`,
 * `Blocks` or `HasFollowUp`. The target of a `Blocks` row reads `BlockedBy`,
 * and the target of a `HasFollowUp` row reads `FollowUpOf`.
 */
enum CardLinkKind: string
{
    case RelatesTo = 'relates-to';
    case Blocks = 'blocks';
    case BlockedBy = 'blocked-by';
    case HasFollowUp = 'has-follow-up';
    case FollowUpOf = 'follow-up-of';

    public function inverse(): self
    {
        return match ($this) {
            self::RelatesTo => self::RelatesTo,
            self::Blocks => self::BlockedBy,
            self::BlockedBy => self::Blocks,
            self::HasFollowUp => self::FollowUpOf,
            self::FollowUpOf => self::HasFollowUp,
        };
    }

    /**
     * @return array{self, bool} the kind a row stores, and whether the reader is its target
     */
    public function stored(): array
    {
        return match ($this) {
            self::BlockedBy, self::FollowUpOf => [$this->inverse(), true],
            default => [$this, false],
        };
    }

    public function translationKey(): string
    {
        return match ($this) {
            self::RelatesTo => 'board.card.linked_cards.kind.relates_to',
            self::Blocks => 'board.card.linked_cards.kind.blocks',
            self::BlockedBy => 'board.card.linked_cards.kind.blocked_by',
            self::HasFollowUp => 'board.card.linked_cards.kind.has_follow_up',
            self::FollowUpOf => 'board.card.linked_cards.kind.follow_up_of',
        };
    }
}
