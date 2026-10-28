<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class RepositorySettingsReaders
{
    /** @param iterable<RepositorySettingsReader> $readers */
    public function __construct(
        #[AutowireIterator('app.repository_settings_reader')]
        private iterable $readers,
    ) {
    }

    public function for(string $forge): ?RepositorySettingsReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge)) {
                return $reader;
            }
        }

        return null;
    }
}
