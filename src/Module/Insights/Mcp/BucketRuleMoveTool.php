<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\ListBucketRulesCommand;
use App\Module\Insights\Command\ListBucketRulesHandler;
use App\Module\Insights\Command\MoveBucketRuleCommand;
use App\Module\Insights\Command\MoveBucketRuleHandler;
use App\Module\Insights\Entity\BucketRuleDirection;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Swaps a time bucket rule with its neighbour.
 *
 * @phpstan-import-type BucketRuleListPayload from BucketRuleListTool
 */
#[McpTool(name: self::NAME, description: 'Move a time bucket rule one place up or down, by its id from bucket_rule_list. A tool call takes the bucket of the first rule that matches it, so the order decides. direction is up or down. The first rule cannot move up and the last cannot move down, so those moves change nothing. The bucket times of the project are computed again. The response is the rules, as bucket_rule_list answers them.')]
final readonly class BucketRuleMoveTool
{
    public const string NAME = 'bucket_rule_move';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private BucketRuleFinder $finder,
        private MoveBucketRuleHandler $moveRule,
        private ListBucketRulesHandler $listRules,
    ) {
    }

    /**
     * @param string $ruleId    the id of the rule, from bucket_rule_list
     * @param string $direction up or down
     *
     * @return BucketRuleListPayload
     */
    public function __invoke(string $ruleId, string $direction): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            $parsed = BucketRuleDirection::tryFrom($direction) ?? throw new ToolCallException('direction: Use up or down.');

            ($this->moveRule)(new MoveBucketRuleCommand($this->finder->find($project, $ruleId), $parsed));

            return BucketRuleListTool::payload(($this->listRules)(new ListBucketRulesCommand($project)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The bucket rule could not be moved. The error has been logged.', previous: $e);
        }
    }
}
