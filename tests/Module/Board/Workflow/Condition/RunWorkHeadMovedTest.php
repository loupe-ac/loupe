<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\Condition\RunWorkHeadMoved;
use App\Module\Board\Workflow\WorkAtHeadFacts;
use App\Module\Workflow\Contract\EngineFact;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RunWorkHeadMovedTest extends TestCase
{
    public function test_it_is_true_when_a_live_request_of_the_kind_named_an_older_head_of_the_bound_pull_request(): void
    {
        $id = Uuid::v7();
        $facts = FactsMother::facts(
            pullRequest: FactsMother::pullRequest(id: $id),
            provided: [WorkAtHeadFacts::class => new WorkAtHeadFacts([], [$id->toRfc4122() => 'bbbbbbb2'], [$id->toRfc4122() => ['fix']])],
        );

        self::assertTrue(new RunWorkHeadMoved()->evaluate($facts, ['kind' => 'fix']));
        self::assertFalse(new RunWorkHeadMoved()->evaluate($facts, ['kind' => 'review']));
    }

    public function test_it_is_false_when_the_moved_request_belongs_to_another_pull_request(): void
    {
        $other = Uuid::v7();
        $facts = FactsMother::facts(
            pullRequest: FactsMother::pullRequest(id: Uuid::v7()),
            provided: [WorkAtHeadFacts::class => new WorkAtHeadFacts([], [], [$other->toRfc4122() => ['fix']])],
        );

        self::assertFalse(new RunWorkHeadMoved()->evaluate($facts, ['kind' => 'fix']));
    }

    public function test_it_is_false_when_the_rule_acts_on_no_pull_request(): void
    {
        $facts = FactsMother::facts(provided: [WorkAtHeadFacts::class => new WorkAtHeadFacts([], [], ['x' => ['fix']])]);

        self::assertFalse(new RunWorkHeadMoved()->evaluate($facts, ['kind' => 'fix']));
    }

    public function test_it_reads_its_facts_and_the_pull_request(): void
    {
        self::assertSame([WorkAtHeadFacts::class, EngineFact::PullRequest], new RunWorkHeadMoved()->reads(['kind' => 'fix']));
        self::assertSame('workflow.waiting.run_work_head_moved', new RunWorkHeadMoved()->waitingFor(['kind' => 'fix'])->getMessage());
        self::assertSame('workflow.waiting.not.run_work_head_moved', new RunWorkHeadMoved()->waitingFor(['kind' => 'fix'], true)->getMessage());
    }
}
