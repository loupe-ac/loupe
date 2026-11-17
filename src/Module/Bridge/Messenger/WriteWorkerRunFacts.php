<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

/** A bridge report changed these runs, so their fact rows need a new write. */
final readonly class WriteWorkerRunFacts
{
    /** @param list<string> $runIds RFC 4122 strings */
    public function __construct(
        public string $projectId,
        public array $runIds,
    ) {
    }
}
