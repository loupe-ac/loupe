<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/** A module tells the move guard that work of the app runs on a card, so a person cannot move the card until the work ends. */
#[AutoconfigureTag('app.workflow_card_move_lock')]
interface CardMoveLock
{
    public function locks(Uuid $cardId): bool;
}
