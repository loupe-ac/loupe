<?php

declare(strict_types=1);

namespace App\Module\Insights\Event;

/** The bucket rules of a project changed. Dispatched after the transaction commits, with ids only. */
final readonly class BucketRulesChanged
{
    public function __construct(
        public string $projectId,
        /** Null when the change names no one rule, as an accepted proposal does. */
        public ?string $ruleId,
    ) {
    }
}
