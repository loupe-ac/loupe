<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use Symfony\Component\Uid\Uuid;

/** One live preview token of a person, as a list shows it. It never holds the token itself. */
final readonly class PreviewTokenRow
{
    public function __construct(
        public string $accessTokenId,
        public Uuid $projectId,
        public string $projectName,
        public ?Uuid $cardId,
        public ?int $cardNumber,
        public ?string $cardTitle,
        public string $label,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
