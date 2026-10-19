<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Names the slot of the column where the cards of ticked discovery proposals land. */
interface ProposalSlot
{
    /** The slot key, or null when the app names none. */
    public function proposalSlot(): ?string;
}
