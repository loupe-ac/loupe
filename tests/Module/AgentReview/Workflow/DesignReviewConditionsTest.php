<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\Condition\DesignReviewDue;
use App\Module\AgentReview\Workflow\Condition\DesignReviewFinished;
use App\Module\AgentReview\Workflow\Condition\DesignReviewFixDue;
use App\Module\AgentReview\Workflow\Condition\DesignReviewRunning;
use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DesignReviewConditionsTest extends TestCase
{
    private const array PARAMS = ['tag' => 'tech-design', 'rounds' => 3];

    /** @return iterable<string, array{DocumentReviewState, bool, bool, bool, bool}> due, fix_due, finished, running */
    public static function loops(): iterable
    {
        yield 'no review' => [self::state(), true, false, false, true];
        yield 'failed round 1, not answered' => [self::state(AgentReviewConclusion::Failure, failed: 1), false, true, false, true];
        yield 'failed round 1, answered' => [self::state(AgentReviewConclusion::Failure, answered: true, failed: 1), true, false, false, true];
        yield 'failed review of an older version' => [self::state(AgentReviewConclusion::Failure, failed: 1, version: 2, reviewVersion: 1), true, false, false, true];
        yield 'third failure, not answered' => [self::state(AgentReviewConclusion::Failure, failed: 3), false, false, true, false];
        yield 'third failure, answered' => [self::state(AgentReviewConclusion::Failure, answered: true, failed: 3), false, false, true, false];
        yield 'passed with a question, not answered' => [self::state(AgentReviewConclusion::Success, needsPerson: true), false, true, false, true];
        yield 'passed with a question, answered before the revision' => [self::state(AgentReviewConclusion::Success, answered: true, needsPerson: true), true, false, false, true];
        yield 'passed without a question' => [self::state(AgentReviewConclusion::Success), false, false, true, false];
        yield 'passed with a question, answered with a revision' => [self::state(AgentReviewConclusion::Success, answered: true, needsPerson: true, version: 2, reviewVersion: 1), true, false, false, true];
        yield 'passed review of an older version' => [self::state(AgentReviewConclusion::Success, version: 2, reviewVersion: 1), true, false, false, true];
        yield 'passed review of an older version after two failures' => [self::state(AgentReviewConclusion::Success, failed: 2, version: 3, reviewVersion: 2), true, false, false, true];
        yield 'passed with a question at the round limit, not answered' => [self::state(AgentReviewConclusion::Success, needsPerson: true, failed: 2, ownerPasses: 1), false, true, false, true];
        yield 'passed with a question at the round limit, answered' => [self::state(AgentReviewConclusion::Success, answered: true, needsPerson: true, failed: 2, ownerPasses: 1, version: 2, reviewVersion: 1), false, false, true, false];
        yield 'failure after a pass with a question reaches the limit' => [self::state(AgentReviewConclusion::Failure, failed: 2, ownerPasses: 1), false, false, true, false];
        yield 'failure after a pass with a question is still below the limit' => [self::state(AgentReviewConclusion::Failure, failed: 1, ownerPasses: 1), false, true, false, true];
        yield 'passed after two failures' => [self::state(AgentReviewConclusion::Success, failed: 2), false, false, true, false];
    }

    #[DataProvider('loops')]
    public function test_each_condition_reads_the_loop_of_the_document(DocumentReviewState $state, bool $due, bool $fixDue, bool $finished, bool $running): void
    {
        $facts = self::facts($state);

        self::assertSame($due, new DesignReviewDue()->evaluate($facts, self::PARAMS), 'due');
        self::assertSame($fixDue, new DesignReviewFixDue()->evaluate($facts, self::PARAMS), 'fix_due');
        self::assertSame($finished, new DesignReviewFinished()->evaluate($facts, self::PARAMS), 'finished');
        self::assertSame($running, new DesignReviewRunning()->evaluate($facts, self::PARAMS), 'running');
    }

    public function test_a_document_with_another_tag_does_not_count(): void
    {
        $facts = self::facts(self::state(tags: ['product-design']));

        foreach (self::conditions() as $condition) {
            self::assertFalse($condition->evaluate($facts, self::PARAMS), $condition::key());
        }
        self::assertTrue(new DesignReviewDue()->evaluate($facts, ['tag' => 'product-design', 'rounds' => 3]));
    }

    public function test_a_condition_is_true_when_any_document_matches(): void
    {
        $facts = self::facts(self::state(AgentReviewConclusion::Success), self::state());

        self::assertTrue(new DesignReviewDue()->evaluate($facts, self::PARAMS));
        self::assertTrue(new DesignReviewRunning()->evaluate($facts, self::PARAMS));
    }

    public function test_finished_waits_for_every_draft_with_the_tag(): void
    {
        $finished = new DesignReviewFinished();

        self::assertFalse($finished->evaluate(self::facts(self::state(AgentReviewConclusion::Success), self::state()), self::PARAMS));
        self::assertTrue($finished->evaluate(self::facts(self::state(AgentReviewConclusion::Success), self::state(AgentReviewConclusion::Failure, failed: 3)), self::PARAMS));
        self::assertTrue($finished->evaluate(self::facts(self::state(AgentReviewConclusion::Success), self::state(tags: ['product-design'])), self::PARAMS));
    }

    public function test_no_document_makes_every_condition_false(): void
    {
        foreach (self::conditions() as $condition) {
            self::assertFalse($condition->evaluate(self::facts(), self::PARAMS), $condition::key());
        }
    }

    public function test_the_round_limit_decides_when_a_loop_is_finished(): void
    {
        $facts = self::facts(self::state(AgentReviewConclusion::Failure, failed: 2));

        self::assertFalse(new DesignReviewFinished()->evaluate($facts, ['tag' => 'tech-design', 'rounds' => 3]));
        self::assertTrue(new DesignReviewFinished()->evaluate($facts, ['tag' => 'tech-design', 'rounds' => 2]));
    }

    public function test_running_gives_the_round_and_the_limit_to_a_marker(): void
    {
        $condition = new DesignReviewRunning();
        self::assertInstanceOf(MarkerEvidence::class, $condition);

        $evidence = $condition->evidence(self::facts(self::state(AgentReviewConclusion::Failure, answered: true, failed: 2)), self::PARAMS);

        self::assertSame(['%round%' => 3, '%limit%' => 3], $evidence?->params);
        self::assertNull($condition->evidence(self::facts(self::state(AgentReviewConclusion::Success)), self::PARAMS));
    }

    public function test_the_round_label_counts_passes_with_a_question_and_stops_at_the_limit(): void
    {
        $condition = new DesignReviewRunning();

        $second = $condition->evidence(self::facts(self::state(AgentReviewConclusion::Success, needsPerson: true, failed: 1, ownerPasses: 1)), self::PARAMS);
        $capped = $condition->evidence(self::facts(self::state(AgentReviewConclusion::Success, needsPerson: true, failed: 2, ownerPasses: 1)), self::PARAMS);

        self::assertSame(['%round%' => 3, '%limit%' => 3], $second?->params);
        self::assertSame(['%round%' => 3, '%limit%' => 3], $capped?->params);
    }

    public function test_each_condition_declares_its_parameters_and_reads(): void
    {
        foreach (self::conditions() as $condition) {
            self::assertStringStartsWith('design_review.', $condition::key());
            self::assertSame('workflow.source.agent_review', $condition::source());
            self::assertSame([DocumentReviewFacts::class], $condition->reads(self::PARAMS));
            self::assertInstanceOf(ReadsDocumentTag::class, $condition);
            self::assertSame('tech-design', $condition->documentTag(self::PARAMS));
            $parameters = $condition::parameters();
            self::assertSame(['tag', 'rounds'], array_map(static fn ($parameter): string => $parameter->name, $parameters));
            self::assertSame(1, $parameters[1]->min);
        }
    }

    public function test_each_condition_has_a_waiting_message_for_both_senses(): void
    {
        foreach (self::conditions() as $condition) {
            $id = str_replace('.', '_', $condition::key());
            self::assertSame('workflow.waiting.'.$id, $condition->waitingFor(self::PARAMS)->getMessage());
            self::assertSame('workflow.waiting.not.'.$id, $condition->waitingFor(self::PARAMS, negated: true)->getMessage());
            self::assertSame(['%tag%' => 'tech-design'], $condition->waitingFor(self::PARAMS)->getParameters());
        }
    }

    /** @return list<Condition> */
    private static function conditions(): array
    {
        return [new DesignReviewDue(), new DesignReviewFixDue(), new DesignReviewFinished(), new DesignReviewRunning()];
    }

    /** @param list<string> $tags */
    private static function state(?AgentReviewConclusion $conclusion = null, bool $answered = false, bool $needsPerson = false, int $failed = 0, int $ownerPasses = 0, int $version = 1, ?int $reviewVersion = null, array $tags = ['tech-design']): DocumentReviewState
    {
        return new DocumentReviewState('doc-'.uniqid(), $tags, $version, null === $conclusion ? null : 'review-'.uniqid(), null === $conclusion ? null : $reviewVersion ?? $version, $conclusion, $answered, $needsPerson, $failed, $ownerPasses);
    }

    private static function facts(DocumentReviewState ...$states): Facts
    {
        return FactsMother::facts(provided: [DocumentReviewFacts::class => new DocumentReviewFacts(array_values($states))]);
    }
}
