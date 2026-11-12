<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Project\Entity\Project;

/** Finds a bucket rule inside one project. A malformed id and a rule of another project read as a rule that does not exist. */
final readonly class BucketRuleLookup
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    public function find(Project $project, string $ruleId): ?InsightsBucketRule
    {
        return $this->insightsBucketRules->findOneByIdAndProjectId($ruleId, (string) $project->id);
    }
}
