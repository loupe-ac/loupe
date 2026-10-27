<?php

declare(strict_types=1);

namespace App\Module\Landing\ValueObject;

/** The FAQ page, rendered from its Markdown file. */
final readonly class FaqContent
{
    /** @param list<FaqQuestion> $questions */
    public function __construct(
        public string $title,
        public string $description,
        public \DateTimeImmutable $updatedOn,
        public string $html,
        public array $questions,
    ) {
    }
}
