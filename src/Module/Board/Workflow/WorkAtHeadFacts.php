<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Facts;

/** The kinds of work that ended done at the current head of each open pull request of one card. */
final readonly class WorkAtHeadFacts
{
    /**
     * @param array<string, list<string>> $doneKinds sorted kinds, keyed by the id of the open pull request that has a head commit
     * @param array<string, string>       $heads     the head commit of each of those pull requests
     */
    public function __construct(
        public array $doneKinds,
        public array $heads,
    ) {
    }

    /** Whether work of the kind ended done at the current head of the pull request a rule acts on. */
    public function doneAtHead(Facts $facts, string $kind): bool
    {
        $id = $facts->pullRequest?->id;

        return null !== $id && \in_array($kind, $this->doneKinds[(string) $id] ?? [], true);
    }
}
