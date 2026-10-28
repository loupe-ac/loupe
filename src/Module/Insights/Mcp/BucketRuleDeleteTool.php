<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\DeleteBucketRuleCommand;
use App\Module\Insights\Command\DeleteBucketRuleHandler;
use App\Module\Insights\Command\ListBucketRulesCommand;
use App\Module\Insights\Command\ListBucketRulesHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Deletes a time bucket rule.
 *
 * @phpstan-import-type BucketRuleListPayload from BucketRuleListTool
 */
#[McpTool(name: self::NAME, description: 'Delete a time bucket rule, by its id from bucket_rule_list. The tool calls it matched fall to a later rule or to the other bucket, and the bucket times of the project are computed again. The response is the rules that remain, as bucket_rule_list answers them.')]
final readonly class BucketRuleDeleteTool
{
    public const string NAME = 'bucket_rule_delete';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private BucketRuleFinder $finder,
        private DeleteBucketRuleHandler $deleteRule,
        private ListBucketRulesHandler $listRules,
    ) {
    }

    /**
     * @param string $ruleId the id of the rule, from bucket_rule_list
     *
     * @return BucketRuleListPayload
     */
    public function __invoke(string $ruleId): array
    {
        try {
            $project = $this->subjects->requireWritableProject();

            ($this->deleteRule)(new DeleteBucketRuleCommand($this->finder->find($project, $ruleId)));

            return BucketRuleListTool::payload(($this->listRules)(new ListBucketRulesCommand($project)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The bucket rule could not be deleted. The error has been logged.', previous: $e);
        }
    }
}
