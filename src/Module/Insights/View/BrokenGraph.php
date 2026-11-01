<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** The part of a saved graph that is gone. The name is the template metric key, the bucket name or the harness event kind. */
final readonly class BrokenGraph
{
    public function __construct(
        public BrokenGraphReason $reason,
        public string $name,
    ) {
    }
}
