<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/**
 * The kinds of the open or claimed work requests of the card, each once, with the time each request was made.
 * The rule kinds are the same list less the requests that a person started.
 */
final readonly class WorkRequestFacts
{
    /**
     * @param list<string>                      $activeKinds
     * @param array<string, \DateTimeImmutable> $since           kind => the time of its live request
     * @param list<string>                      $activeRuleKinds
     * @param array<string, \DateTimeImmutable> $ruleSince       kind => the time of its live request that a rule made
     */
    public function __construct(
        public array $activeKinds,
        public array $since,
        public array $activeRuleKinds,
        public array $ruleSince,
    ) {
    }
}
