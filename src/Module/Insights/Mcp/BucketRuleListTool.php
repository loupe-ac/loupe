<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\ListBucketRulesCommand;
use App\Module\Insights\Command\ListBucketRulesHandler;
use App\Module\Insights\Command\ListBucketRulesView;
use App\Module\Insights\Entity\InsightsBucketRule;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the time bucket rules of the project.
 *
 * @phpstan-type BucketRulePayload array{id: string, position: int, pattern: string, bucket: string}
 * @phpstan-type BucketRuleListPayload array{rules: list<BucketRulePayload>, limit: int}
 */
#[McpTool(name: self::NAME, description: 'List the time bucket rules of this project, in the order they apply. A tool call takes the bucket of the first rule whose pattern matches it. Each rule gives its id, its position, its pattern and its bucket. The pattern is a glob of at most 120 characters, where * matches any run of characters and ? matches one. limit is the most rules a project holds. Change the rules with bucket_rule_create, bucket_rule_move and bucket_rule_delete.')]
final readonly class BucketRuleListTool
{
    public const string NAME = 'bucket_rule_list';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ListBucketRulesHandler $listRules,
    ) {
    }

    /** @return BucketRuleListPayload */
    public function __invoke(): array
    {
        try {
            return self::payload(($this->listRules)(new ListBucketRulesCommand($this->subjects->requireReadableProject())));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The bucket rules could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return BucketRuleListPayload */
    public static function payload(ListBucketRulesView $view): array
    {
        return [
            'rules' => array_map(static fn (InsightsBucketRule $rule): array => [
                'id' => (string) $rule->id,
                'position' => $rule->position,
                'pattern' => $rule->pattern,
                'bucket' => $rule->bucket,
            ], $view->rules),
            'limit' => InsightsBucketRule::MAX_PER_PROJECT,
        ];
    }
}
