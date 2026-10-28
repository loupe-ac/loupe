<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Project\Entity\Project;
use Mcp\Exception\ToolCallException;

/** Finds a bucket rule inside the bound project. A rule of another project reads as a rule that does not exist. */
final readonly class BucketRuleFinder
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    public function find(Project $project, string $ruleId): InsightsBucketRule
    {
        return $this->insightsBucketRules->findOneByIdAndProjectId($ruleId, (string) $project->id) ?? throw new ToolCallException('ruleId: No bucket rule of this project has this id. Read the ids with bucket_rule_list.');
    }
}
