<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The work kinds of the open worker runs of the card, each once, with the start of the oldest run of each kind. */
final readonly class WorkerRunFacts
{
    /**
     * @param list<string>                      $activeKinds
     * @param array<string, \DateTimeImmutable> $since       work kind => the start of its oldest open run
     */
    public function __construct(
        public array $activeKinds,
        public array $since,
    ) {
    }
}
