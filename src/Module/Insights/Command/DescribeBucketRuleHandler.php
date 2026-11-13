<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsBucketRuleRepository;

/** Reads one bucket rule with its place in the order the rules apply. */
final readonly class DescribeBucketRuleHandler
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    public function __invoke(DescribeBucketRuleCommand $command): BucketRuleRow
    {
        $place = array_find_key(
            $this->insightsBucketRules->findOrderedFresh($command->rule->project),
            static fn ($other): bool => $other->id?->equals($command->rule->id) ?? false,
        );

        return new BucketRuleRow($command->rule, $place ?? throw new \LogicException('A stored rule is in the rules of its project.'));
    }
}
