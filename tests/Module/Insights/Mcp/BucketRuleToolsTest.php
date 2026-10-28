<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Mcp;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Mcp\BucketRuleCreateTool;
use App\Module\Insights\Mcp\BucketRuleDeleteTool;
use App\Module\Insights\Mcp\BucketRuleListTool;
use App\Module\Insights\Mcp\BucketRuleMoveTool;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BucketRuleToolsTest extends KernelTestCase
{
    use InsightsScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_list_answers_no_rules_for_a_new_project(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-empty'));

        self::assertSame(['rules' => [], 'limit' => InsightsBucketRule::MAX_PER_PROJECT], $this->tool(BucketRuleListTool::class)());
    }

    public function test_create_appends_rules_and_list_answers_them_in_order(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-create'));

        $this->tool(BucketRuleCreateTool::class)('LSP', 'lsp');
        $result = $this->tool(BucketRuleCreateTool::class)('mcp__loupe__*', 'loupe');

        self::assertSame(['LSP', 'mcp__loupe__*'], array_column($result['rules'], 'pattern'));
        self::assertSame(['lsp', 'loupe'], array_column($result['rules'], 'bucket'));
        self::assertSame($result, $this->tool(BucketRuleListTool::class)());
        self::assertNotSame('', $result['rules'][0]['id']);
        self::assertLessThan($result['rules'][1]['position'], $result['rules'][0]['position']);
    }

    public function test_move_swaps_a_rule_with_its_neighbour(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-move'));
        $this->tool(BucketRuleCreateTool::class)('a', 'first');
        $rules = $this->tool(BucketRuleCreateTool::class)('b', 'second')['rules'];

        $result = $this->tool(BucketRuleMoveTool::class)($rules[1]['id'], 'up');
        self::assertSame(['second', 'first'], array_column($result['rules'], 'bucket'));

        $unchanged = $this->tool(BucketRuleMoveTool::class)($rules[1]['id'], 'up');
        self::assertSame(['second', 'first'], array_column($unchanged['rules'], 'bucket'));
    }

    public function test_move_refuses_an_unknown_direction(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-direction'));
        $rules = $this->tool(BucketRuleCreateTool::class)('a', 'first')['rules'];

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('direction: Use up or down.');

        $this->tool(BucketRuleMoveTool::class)($rules[0]['id'], 'sideways');
    }

    public function test_delete_removes_the_rule(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-delete'));
        $this->tool(BucketRuleCreateTool::class)('a', 'first');
        $rules = $this->tool(BucketRuleCreateTool::class)('b', 'second')['rules'];

        $result = $this->tool(BucketRuleDeleteTool::class)($rules[0]['id']);

        self::assertSame(['second'], array_column($result['rules'], 'bucket'));
    }

    public function test_create_turns_the_domain_errors_into_readable_text(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-invalid'));

        try {
            $this->tool(BucketRuleCreateTool::class)(' ', 'Bad Name');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame(
                "pattern: A pattern must not be blank.\nbucket: A bucket name is 1 to 64 characters: lower case letters, digits, underscore and hyphen.",
                $e->getMessage(),
            );
        }
        self::assertSame([], $this->tool(BucketRuleListTool::class)()['rules']);
    }

    public function test_create_refuses_a_rule_past_the_limit(): void
    {
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-limit'));
        for ($i = 0; $i < InsightsBucketRule::MAX_PER_PROJECT; ++$i) {
            $this->tool(BucketRuleCreateTool::class)('p'.$i, 'b'.$i);
        }

        try {
            $this->tool(BucketRuleCreateTool::class)('extra', 'extra');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('pattern: A project holds at most 50 rules. Delete a rule with bucket_rule_delete first.', $e->getMessage());
        }
        self::assertCount(InsightsBucketRule::MAX_PER_PROJECT, $this->tool(BucketRuleListTool::class)()['rules']);
    }

    public function test_move_and_delete_refuse_the_rule_of_another_project(): void
    {
        $other = $this->scenarioProject('rules-other');
        $this->actAsMcpTokenBoundTo($other);
        $foreign = $this->tool(BucketRuleCreateTool::class)('a', 'first')['rules'][0]['id'];
        $this->actAsMcpTokenBoundTo($this->scenarioProject('rules-mine'));
        $this->tool(BucketRuleCreateTool::class)('mine', 'mine');

        foreach ([
            fn () => $this->tool(BucketRuleMoveTool::class)($foreign, 'down'),
            fn () => $this->tool(BucketRuleDeleteTool::class)($foreign),
            fn () => $this->tool(BucketRuleDeleteTool::class)('not-an-id'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (ToolCallException $e) {
                self::assertSame('ruleId: No bucket rule of this project has this id. Read the ids with bucket_rule_list.', $e->getMessage());
            }
        }

        $this->actAsMcpTokenBoundTo($other);
        self::assertCount(1, $this->tool(BucketRuleListTool::class)()['rules']);
    }

    public function test_every_tool_refuses_an_unbound_token(): void
    {
        $project = $this->scenarioProject('rules-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        foreach ([
            fn () => $this->tool(BucketRuleListTool::class)(),
            fn () => $this->tool(BucketRuleCreateTool::class)('a', 'b'),
            fn () => $this->tool(BucketRuleMoveTool::class)('x', 'up'),
            fn () => $this->tool(BucketRuleDeleteTool::class)('x'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (ToolCallException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): object
    {
        $tool = self::getContainer()->get($class);
        self::assertInstanceOf($class, $tool);

        return $tool;
    }
}
