<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsBucketRule;

final readonly class PlaceBucketRuleCommand
{
    public function __construct(
        public InsightsBucketRule $rule,
        /** The place to take, counted from 0. A place past the last puts the rule last. */
        public int $place,
    ) {
    }
}
