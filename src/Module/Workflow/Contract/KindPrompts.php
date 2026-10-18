<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The prompt that Loupe ships for a kind of work, or null when it ships none. */
interface KindPrompts
{
    public function forKind(string $kind): ?string;
}
