<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class InitPullRequestReaders
{
    /** @param iterable<InitPullRequestReader> $readers */
    public function __construct(
        #[AutowireIterator('app.init_pull_request_reader')]
        private iterable $readers,
    ) {
    }

    public function for(string $forge): ?InitPullRequestReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge)) {
                return $reader;
            }
        }

        return null;
    }
}
