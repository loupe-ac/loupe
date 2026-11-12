<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsBucketRule;

/** A bucket rule with its place in the order the rules apply. */
final readonly class BucketRuleRow
{
    public function __construct(
        public InsightsBucketRule $rule,
        /** Counted from 0. */
        public int $place,
    ) {
    }
}
