<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Inbox asks this port which work does the stage of a card, and the workflow template of the project names it. */
interface StageWorks
{
    /**
     * The kind of work of the slot that the column of each card links to. A card is absent when it sits in the
     * backlog, in a terminal column or in a column with no slot, when the slot names none, or when it is in no such project.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, string> the kind of work, by the RFC 4122 card id
     */
    public function ofCards(Uuid $projectId, array $cardIds): array;
}
