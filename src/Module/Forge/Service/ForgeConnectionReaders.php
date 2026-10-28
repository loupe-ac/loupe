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

    /**
     * The readers whose forge the project is connected to, each asked once. A forge in `$watchedForges` already proves the connection.
     *
     * @param list<string> $watchedForges
     *
     * @return list<ForgeConnectionReader>
     */
    public function connected(Project $project, array $watchedForges): array
    {
        $connected = [];
        foreach ($this->readers as $reader) {
            if (array_any($watchedForges, $reader->supports(...)) || $reader->isConnected($project)) {
                $connected[] = $reader;
            }
        }

        return $connected;
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
