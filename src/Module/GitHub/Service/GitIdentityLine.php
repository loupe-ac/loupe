<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** One machine of the Git identity panel. */
final readonly class GitIdentityLine
{
    public function __construct(
        public string $bridgeLabel,
        public GitIdentityState $state,
        public string $commitName,
        public string $pushLogin,
        public ?\DateTimeImmutable $reportedAt,
    ) {
    }
}
