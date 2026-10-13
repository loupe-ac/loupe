<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** The oldest open question that names the card, null when it has none. */
final readonly class OpenQuestionFacts
{
    public function __construct(
        public ?OpenQuestion $question,
    ) {
    }
}
