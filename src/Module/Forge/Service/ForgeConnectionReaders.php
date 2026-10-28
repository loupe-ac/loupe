<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ForgeConnectionReaders
{
    /** @param iterable<ForgeConnectionReader> $readers */
    public function __construct(
        #[AutowireIterator('app.forge_connection_reader')]
        private iterable $readers,
    ) {
    }

    /** The first reader that accepts the path and whose forge the project is connected to. */
    public function for(Project $project, string $path): ?ForgeConnectionReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->acceptsPath($path) && $reader->isConnected($project)) {
                return $reader;
            }
        }

        return null;
    }

    /** The reader of one forge that accepts the path. A watched repository already proves the connection. */
    public function forForge(string $forge, string $path): ?ForgeConnectionReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge) && $reader->acceptsPath($path)) {
                return $reader;
            }
        }

        return null;
    }
}
