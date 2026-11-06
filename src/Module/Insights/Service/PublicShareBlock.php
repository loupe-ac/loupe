<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** A reason that stops a project from sharing an item. */
enum PublicShareBlock: string
{
    case FlagOff = 'flag-off';

    /** The key of the sentence that tells the owner why. */
    public function translationKey(): string
    {
        return 'insights.public_link.block.'.str_replace('-', '_', $this->value);
    }
}
