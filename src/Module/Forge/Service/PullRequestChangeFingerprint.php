<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** Two sha256 hashes of the change of one pull request head against its base. */
final readonly class PullRequestChangeFingerprint
{
    public function __construct(
        /** Leaves the line numbers out, so a merge of the base that only moves the change keeps it. */
        public string $change,
        /** Keeps the line numbers. */
        public string $lines,
    ) {
    }
}
