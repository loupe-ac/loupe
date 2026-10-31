<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\View;

/** What the account section shows: the account key, if there is one. */
final readonly class AccountOpenRouterView
{
    public function __construct(
        public ?OpenRouterKeySummary $key,
    ) {
    }
}
