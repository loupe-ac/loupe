<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One action that a worker suggests for a stopped run, with its one-line reason. */
final readonly class SuggestedAction
{
    public function __construct(
        public string $action,
        public string $reason,
        public bool $recommended = false,
    ) {
    }
}
