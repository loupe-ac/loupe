<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a condition saw when it matched: when it began, and the values a marker label shows. */
final readonly class Evidence
{
    /** @param array<string, string|int> $params the label parameters, such as `%number%` */
    public function __construct(
        public ?\DateTimeImmutable $since = null,
        public array $params = [],
    ) {
    }
}
