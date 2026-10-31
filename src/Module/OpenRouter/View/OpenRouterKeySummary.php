<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\View;

use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use Symfony\Component\Uid\Uuid;

/**
 * What a page shows of a key. It holds no secret, and a query that builds it
 * selects none, so an unreadable encryption key cannot break a page.
 */
final readonly class OpenRouterKeySummary
{
    public function __construct(
        public Uuid $id,
        public bool $forProject,
        public string $lastFour,
        public OpenRouterKeyState $state,
        public ?\DateTimeImmutable $refusedSince,
        public ?\DateTimeImmutable $lastReadAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function isRefused(): bool
    {
        return OpenRouterKeyState::Refused === $this->state;
    }

    public function masked(): string
    {
        return 'sk-or-v1-…'.$this->lastFour;
    }
}
