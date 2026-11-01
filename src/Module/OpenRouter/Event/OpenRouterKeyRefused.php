<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Event;

use Symfony\Component\Uid\Uuid;

/**
 * OpenRouter refused a key on a cost read of $projectId. Dispatched after the
 * key state commits, once for each refused read, with ids only.
 */
final readonly class OpenRouterKeyRefused
{
    public function __construct(
        public Uuid $keyId,
        public bool $forProject,
        public Uuid $projectId,
    ) {
    }
}
