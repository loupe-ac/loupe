<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Event;

use Symfony\Component\Uid\Uuid;

/** A key was deleted or replaced. Dispatched after the delete commits, with the id only. */
final readonly class OpenRouterKeyRemoved
{
    public function __construct(
        public Uuid $keyId,
    ) {
    }
}
