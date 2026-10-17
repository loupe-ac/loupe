<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** The open questions that name the card, oldest first. */
final readonly class OpenQuestionFacts
{
    /** @param list<OpenQuestion> $questions */
    public function __construct(
        public array $questions,
    ) {
    }

    /** The oldest open question, of any kind when $blocking is null, or the oldest whose blocking flag equals $blocking. */
    public function oldest(?bool $blocking = null): ?OpenQuestion
    {
        return array_find($this->questions, static fn (OpenQuestion $question): bool => null === $blocking || $blocking === $question->blocking);
    }
}
