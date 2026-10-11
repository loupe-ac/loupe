<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\Condition\RunDoneAtHead;
use App\Module\Board\Workflow\WorkAtHeadFacts;
use App\Module\Workflow\Contract\EngineFact;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RunDoneAtHeadTest extends TestCase
{
    public function test_it_is_true_when_the_kind_is_done_at_the_head_of_the_bound_pull_request(): void
    {
        $id = Uuid::v7();
        $facts = FactsMother::facts(
            pullRequest: FactsMother::pullRequest(id: $id),
            provided: [WorkAtHeadFacts::class => new WorkAtHeadFacts([$id->toRfc4122() => ['epic-preview']], [$id->toRfc4122() => 'aaaaaaa1'])],
        );

        self::assertTrue(new RunDoneAtHead()->evaluate($facts, ['kind' => 'epic-preview']));
        self::assertFalse(new RunDoneAtHead()->evaluate($facts, ['kind' => 'fix']));
    }

    public function test_it_is_false_for_a_pull_request_that_has_no_such_work(): void
    {
        $covered = Uuid::v7();
        $facts = FactsMother::facts(
            pullRequest: FactsMother::pullRequest(id: Uuid::v7()),
            provided: [WorkAtHeadFacts::class => new WorkAtHeadFacts([$covered->toRfc4122() => ['epic-preview']], [])],
        );

        self::assertFalse(new RunDoneAtHead()->evaluate($facts, ['kind' => 'epic-preview']));
    }

    public function test_it_is_false_when_the_rule_acts_on_no_pull_request(): void
    {
        $facts = FactsMother::facts(provided: [WorkAtHeadFacts::class => new WorkAtHeadFacts(['x' => ['epic-preview']], [])]);

        self::assertFalse(new RunDoneAtHead()->evaluate($facts, ['kind' => 'epic-preview']));
    }

    public function test_it_reads_its_facts_and_the_pull_request(): void
    {
        self::assertSame([WorkAtHeadFacts::class, EngineFact::PullRequest], new RunDoneAtHead()->reads(['kind' => 'epic-preview']));
    }
}
