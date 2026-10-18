<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** An installation token limited to one repository, with the moment GitHub ends it. */
final readonly class GitHubScopedToken
{
    /** @param non-empty-string $token */
    public function __construct(
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
