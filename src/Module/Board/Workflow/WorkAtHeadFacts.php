<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Facts;

/** The kinds of work that ended done at the current head of each open pull request of one card, and the live kinds that named an older head. */
final readonly class WorkAtHeadFacts
{
    /**
     * @param array<string, list<string>> $doneKinds  sorted kinds, keyed by the id of the open pull request that has a head commit
     * @param array<string, string>       $heads      the head commit of each of those pull requests
     * @param array<string, list<string>> $movedKinds sorted kinds of the live requests that named an older head, keyed the same way
     */
    public function __construct(
        public array $doneKinds,
        public array $heads,
        public array $movedKinds = [],
    ) {
    }

    /** Whether work of the kind ended done at the current head of the pull request a rule acts on. */
    public function doneAtHead(Facts $facts, string $kind): bool
    {
        $id = $facts->pullRequest?->id;

        return null !== $id && \in_array($kind, $this->doneKinds[(string) $id] ?? [], true);
    }

    /**
     * Whether a live request of the kind named an older head of any open pull request of the card.
     * It reads every pull request, not only the one a rule acts on, because a card holds one live request of a kind at most.
     */
    public function headMoved(string $kind): bool
    {
        return array_any($this->movedKinds, static fn (array $kinds): bool => \in_array($kind, $kinds, true));
    }
}
