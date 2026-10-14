<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Inbox asks this port which work does the stage of a card, and the workflow template of the project names it. */
interface StageWorks
{
    /**
     * @return ?string the kind of work of the slot that the column of the card links to, or null when the card sits
     *                 in the backlog, in a terminal column or in a column with no slot, or the slot names none
     */
    public function of(Uuid $projectId, Uuid $cardId): ?string;
}
