<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Event;

use Symfony\Component\Uid\Uuid;

/** A cost read worked with a key that was refused. Dispatched after the key state commits, with ids only. */
final readonly class OpenRouterKeyWorks
{
    public function __construct(
        public Uuid $keyId,
        public bool $forProject,
        public Uuid $projectId,
    ) {
    }
}
