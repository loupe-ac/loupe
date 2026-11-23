<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

/** An ask that the template logs instead of asking: the label and the option the agent goes on with. */
final readonly class AskInboxLogged
{
    public function __construct(
        public string $label,
        public string $title,
        public string $option,
    ) {
    }
}
