<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** How the choice of a decision came to stand. */
enum InboxDecisionMadeBy: string
{
    /** The agent made the choice and the template only logged it. */
    case Logged = 'logged';
    /** Loupe answered an ask with the recommended option, after its deadline. */
    case Defaulted = 'defaulted';
    /** The owner changed the choice. */
    case Changed = 'changed';
}
