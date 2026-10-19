<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The kind of a card marker. The case order is the precedence, so the first matching kind is the one a card shows. */
enum MarkerKind: string
{
    case Stuck = 'stuck';
    case NeedsYou = 'needs-you';
    case Working = 'working';
    case Waiting = 'waiting';
    case Chip = 'chip';

    /** @return list<self> the kinds that are a state, which leaves out the chip */
    public static function states(): array
    {
        return [self::Stuck, self::NeedsYou, self::Working, self::Waiting];
    }

    /** The translation key of the kind title, such as Stuck or Note. */
    public function translationKey(): string
    {
        return 'board.card_state.'.str_replace('-', '_', $this->value);
    }

    /** The line that says since when the marker holds, with a %time% placeholder. */
    public function sinceKey(): string
    {
        return 'board.card_state.since.'.str_replace('-', '_', $this->value);
    }

    /** The label of the field that holds the start time. */
    public function sinceLabelKey(): string
    {
        return 'board.card_state.since_label.'.str_replace('-', '_', $this->value);
    }

    /** The label of the field that says what clears the marker. */
    public function remedyLabelKey(): string
    {
        return 'board.card_state.remedy_label.'.str_replace('-', '_', $this->value);
    }
}
