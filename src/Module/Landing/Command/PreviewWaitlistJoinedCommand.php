<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

final readonly class PreviewWaitlistJoinedCommand
{
    /** @param 'notify'|'modal' $frame */
    public function __construct(
        public string $frame,
    ) {
    }
}
