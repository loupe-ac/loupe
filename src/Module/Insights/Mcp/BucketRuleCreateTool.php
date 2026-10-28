<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\CreateBucketRuleCommand;
use App\Module\Insights\Command\CreateBucketRuleHandler;
use App\Module\Insights\Command\ListBucketRulesCommand;
use App\Module\Insights\Command\ListBucketRulesHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Adds a time bucket rule to the end of the rules of the project.
 *
 * @phpstan-import-type BucketRuleListPayload from BucketRuleListTool
 */
#[McpTool(name: self::NAME, description: 'Add a time bucket rule to the end of the rules of this project. pattern is a glob of at most 120 characters, where * matches any run of characters and ? matches one, such as LSP or mcp__loupe__*. bucket is the bucket name, 1 to 64 characters: lower case letters, digits, underscore and hyphen. A project holds at most 50 rules. The rule applies to the tool calls of every run, and the bucket times of the project are computed again. The response is the rules, as bucket_rule_list answers them.')]
final readonly class BucketRuleCreateTool
{
    public const string NAME = 'bucket_rule_create';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private CreateBucketRuleHandler $createRule,
        private ListBucketRulesHandler $listRules,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /** @return BucketRuleListPayload */
    public function __invoke(string $pattern, string $bucket): array
    {
        try {
            $project = $this->subjects->requireWritableProject();

            try {
                ($this->createRule)(new CreateBucketRuleCommand($project, $pattern, $bucket));
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e);
            }

            return BucketRuleListTool::payload(($this->listRules)(new ListBucketRulesCommand($project)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The bucket rule could not be saved. The error has been logged.', previous: $e);
        }
    }
}
