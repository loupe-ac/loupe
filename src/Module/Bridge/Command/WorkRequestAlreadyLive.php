<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** A live request of the subject and kind refused the open. It names the rule of that request, or null when the unique index refused it. */
final class WorkRequestAlreadyLive extends \RuntimeException
{
    public function __construct(
        public readonly ?string $ruleId,
    ) {
        parent::__construct('A live work request of the subject and kind exists.');
    }
}
