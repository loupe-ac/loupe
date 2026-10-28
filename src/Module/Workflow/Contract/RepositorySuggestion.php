<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class RepositorySuggestion
{
    public function __construct(
        public RepositorySuggestionKey $key,
        public SuggestionMark $mark,
    ) {
    }
}
