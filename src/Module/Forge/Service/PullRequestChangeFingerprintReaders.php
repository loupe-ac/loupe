<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestChangeFingerprintReaders
{
    /** @param iterable<PullRequestChangeFingerprintReader> $readers */
    public function __construct(
        #[AutowireIterator('app.pull_request_change_fingerprint_reader')]
        private iterable $readers,
    ) {
    }

    public function for(string $forge): ?PullRequestChangeFingerprintReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge)) {
                return $reader;
            }
        }

        return null;
    }
}
