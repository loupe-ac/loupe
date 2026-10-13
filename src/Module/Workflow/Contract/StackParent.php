<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a stacked pull request waits for: the open pull request whose head is its base, or only the base branch when none is stored. */
final readonly class StackParent
{
    /**
     * @param ?int $pullRequest the number of the open parent pull request, null when no pull request has the base as its head
     * @param ?int $card        the lowest number of a card that links the parent pull request
     */
    public function __construct(
        public string $branch,
        public ?int $pullRequest = null,
        public ?int $card = null,
    ) {
    }

    /** True when the parent pull request and a card that links it are both known. */
    public function known(): bool
    {
        return null !== $this->pullRequest && null !== $this->card;
    }
}
