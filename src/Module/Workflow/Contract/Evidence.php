<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a condition saw when it matched: the values a marker label shows, and the thing it is about. */
final readonly class Evidence
{
    /** @param array<string, string|int> $params the label parameters, such as `%number%` */
    public function __construct(
        public array $params = [],
        public ?MarkerSubject $subject = null,
    ) {
    }
}
