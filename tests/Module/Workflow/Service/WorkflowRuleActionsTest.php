<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Workflow\VerdictUnsent;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Contract\BoardSettings;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\NamedFact;
use App\Module\Workflow\Service\WorkflowRuleActions;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkflowRuleActionsTest extends TestCase
{
    /** @return iterable<string, array{Expression}> */
    public static function namedShapes(): iterable
    {
        $unsent = new ConditionLeaf(new VerdictUnsent(), []);
        $inSlot = new ConditionLeaf(new CardInSlot(), ['slot' => 'review']);

        yield 'a named fact' => [new NamedFact('unsent', $unsent)];
        yield 'a named fact inside all' => [new AllOf([new NamedFact('unsent', $unsent), $inSlot])];
        yield 'a named fact of a named fact inside all' => [new AllOf([$inSlot, new NamedFact('outer', new NamedFact('unsent', $unsent))])];
        yield 'an all inside a named fact' => [new NamedFact('ready', new AllOf([$inSlot, $unsent]))];
    }

    #[DataProvider('namedShapes')]
    public function test_a_named_fact_counts_as_its_expression(Expression $when): void
    {
        self::assertSame([['kind' => 'review']], $this->actions($when)->onCondition(Uuid::v7(), VerdictUnsent::key(), 'post-review'));
    }

    public function test_a_rule_on_another_condition_is_not_counted(): void
    {
        $when = new AllOf([new NamedFact('in-review', new ConditionLeaf(new CardInSlot(), ['slot' => 'review']))]);

        self::assertSame([], $this->actions($when)->onCondition(Uuid::v7(), VerdictUnsent::key(), 'post-review'));
    }

    private function actions(Expression $when): WorkflowRuleActions
    {
        $settings = $this->createStub(BoardSettings::class);
        $settings->method('automationEnabled')->willReturn(true);
        $templates = $this->createStub(TemplateSource::class);
        $templates->method('forProject')->willReturn(new Template('test', 1, [], [
            new Rule('verdict', null, $when, [new ActionCall('post-review', ['kind' => 'review'])]),
        ], [], [10], [], 'feature'));

        return new WorkflowRuleActions($settings, $templates);
    }
}
