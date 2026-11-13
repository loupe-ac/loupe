<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsBucketRule;

final readonly class DescribeBucketRuleCommand
{
    public function __construct(
        public InsightsBucketRule $rule,
    ) {
    }
}
