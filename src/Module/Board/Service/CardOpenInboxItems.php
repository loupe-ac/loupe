<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Project\Entity\Project;

/** Reads the open inbox item of each card of a board. The Inbox module implements it. */
interface CardOpenInboxItems
{
    /**
     * One read whatever the card count. A card with several open items maps to the oldest.
     *
     * @return array<string, int> card id => inbox item number, with no key for a card that has no open item
     */
    public function forProject(Project $project): array;
}
