<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The kinds of the open or claimed work requests of the card, each once, with the time each request was made. */
final readonly class WorkRequestFacts
{
    /**
     * @param list<string>                      $activeKinds
     * @param array<string, \DateTimeImmutable> $since       kind => the time of its live request
     */
    public function __construct(
        public array $activeKinds,
        public array $since,
    ) {
    }
}
