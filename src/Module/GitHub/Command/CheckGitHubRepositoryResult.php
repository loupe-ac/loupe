<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class CheckGitHubRepositoryResult
{
    /**
     * @param bool                    $throttled  true when the limiter refused the click and no read happened
     * @param \DateTimeImmutable|null $lastReadAt the time of the last read, set when the click was refused
     */
    public function __construct(
        public bool $throttled,
        public ?\DateTimeImmutable $lastReadAt = null,
    ) {
    }
}
