<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** The translation key of how a closed item ended, with the parameters the key takes. */
final readonly class InboxOutcomeLabel
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public string $key,
        public array $parameters = [],
    ) {
    }
}
