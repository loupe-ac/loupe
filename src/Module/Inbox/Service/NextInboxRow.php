<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\View\InboxView;
use Symfony\Component\Uid\Uuid;

/** Picks the row that opens after the owner acts on one. */
final class NextInboxRow
{
    /**
     * The first row after the acted one that the list still holds, else the
     * nearest one before it. A row the list did not hold before has no place,
     * so it never counts as a neighbour.
     *
     * @param list<string> $before the row keys when the page rendered
     * @param list<string> $after  the row keys after the action
     */
    public static function after(array $before, array $after, string $acted): ?string
    {
        $position = array_search($acted, $before, true);
        if (false === $position) {
            return null;
        }

        foreach (array_slice($before, $position + 1) as $key) {
            if (\in_array($key, $after, true)) {
                return $key;
            }
        }

        foreach (array_reverse(array_slice($before, 0, $position)) as $key) {
            if (\in_array($key, $after, true)) {
                return $key;
            }
        }

        return null;
    }

    /** The view that selects the row with the key, as `InboxRow::key()` spells it. */
    public static function select(InboxView $view, string $key): InboxView
    {
        return str_starts_with($key, 'item-') ? $view->withItem((int) substr($key, 5)) : $view->withAsk(Uuid::fromString($key));
    }
}
