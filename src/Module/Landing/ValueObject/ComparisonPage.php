<?php

declare(strict_types=1);

namespace App\Module\Landing\ValueObject;

/** One comparison page: the facts of its front matter and its rendered body. */
final readonly class ComparisonPage
{
    public function __construct(
        public string $slug,
        public string $competitor,
        public string $title,
        public string $description,
        public \DateTimeImmutable $comparedOn,
        public string $competitorVersion,
        public string $html,
    ) {
    }
}
