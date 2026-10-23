<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\Condition\DesignReviewPassed;
use App\Module\AgentReview\Workflow\DocumentReviewConclusionFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ListsDocuments;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DesignReviewPassedTest extends TestCase
{
    private const array PARAMS = ['tag' => 'tech-design'];

    #[DataProvider('cases')]
    public function test_it_reads_the_newest_conclusion_of_each_document_with_the_tag_in_review(Facts $facts, bool $expected): void
    {
        self::assertSame($expected, new DesignReviewPassed()->evaluate($facts, self::PARAMS));
    }

    /** @return iterable<string, array{Facts, bool}> */
    public static function cases(): iterable
    {
        $success = AgentReviewConclusion::Success;
        $failure = AgentReviewConclusion::Failure;

        yield 'one document, passed' => [self::facts(['one' => $success], self::document('one')), true];
        yield 'one document, failed' => [self::facts(['one' => $failure], self::document('one')), false];
        yield 'one document, no review' => [self::facts([], self::document('one')), false];
        yield 'two documents, one failed' => [self::facts(['one' => $success, 'two' => $failure], self::document('one'), self::document('two')), false];
        yield 'two documents, one with no review' => [self::facts(['one' => $success], self::document('one'), self::document('two')), false];
        yield 'two documents, both passed' => [self::facts(['one' => $success, 'two' => $success], self::document('one'), self::document('two')), true];
        yield 'a failed document with another tag is no concern' => [self::facts(['one' => $success, 'two' => $failure], self::document('one'), self::document('two', tags: ['notes'])), true];
        yield 'a failed approved document is no concern' => [self::facts(['one' => $success, 'two' => $failure], self::document('one'), self::document('two', status: 'approved')), true];
        yield 'a draft is no concern' => [self::facts(['one' => $success], self::document('one'), self::document('two', status: 'draft')), true];
        yield 'no document in review' => [self::facts(['one' => $success], self::document('one', status: 'approved')), false];
        yield 'no document' => [self::facts([]), false];
    }

    public function test_a_success_that_read_an_older_version_does_not_count(): void
    {
        $success = AgentReviewConclusion::Success;
        $revised = new DocumentFacts(['tech-design'], 'in-review', 'one', version: 2);

        self::assertFalse(new DesignReviewPassed()->evaluate(self::factsReadingVersions(['one' => $success], ['one' => 1], $revised), self::PARAMS));
        self::assertTrue(new DesignReviewPassed()->evaluate(self::factsReadingVersions(['one' => $success], ['one' => 2], $revised), self::PARAMS));
        self::assertFalse(new DesignReviewPassed()->evaluate(self::factsReadingVersions(['one' => $success], ['one' => 2], new DocumentFacts(['tech-design'], 'in-review', 'one')), self::PARAMS));
    }

    public function test_a_bound_document_hides_the_others(): void
    {
        $facts = self::facts(['one' => AgentReviewConclusion::Success, 'two' => AgentReviewConclusion::Failure], self::document('one'), self::document('two'));

        self::assertTrue(new DesignReviewPassed()->evaluate($facts->withDocument(self::document('one')), self::PARAMS));
        self::assertFalse(new DesignReviewPassed()->evaluate($facts->withDocument(self::document('two')), self::PARAMS));
    }

    public function test_it_names_what_it_reads_and_what_it_waits_for(): void
    {
        $condition = new DesignReviewPassed();

        self::assertSame('design_review.passed', $condition::key());
        self::assertSame('workflow.source.agent_review', $condition::source());
        self::assertSame('tag', $condition::parameters()[0]->name);
        self::assertSame(1, \count($condition::parameters()));
        self::assertSame([ListsDocuments::class, DocumentReviewConclusionFacts::class], $condition->reads(self::PARAMS));
        self::assertSame('tech-design', $condition->documentTag(self::PARAMS));
        self::assertSame('workflow.waiting.design_review_passed', $condition->waitingFor(self::PARAMS)->getMessage());
        self::assertSame(['%tag%' => 'tech-design'], $condition->waitingFor(self::PARAMS)->getParameters());
        self::assertSame('workflow.waiting.not.design_review_passed', $condition->waitingFor(self::PARAMS, negated: true)->getMessage());
    }

    /** @param list<string> $tags */
    private static function document(string $id, array $tags = ['tech-design'], string $status = 'in-review'): DocumentFacts
    {
        return new DocumentFacts($tags, $status, $id, version: 1);
    }

    /** @param array<string, AgentReviewConclusion> $conclusions every review read version 1 */
    private static function facts(array $conclusions, DocumentFacts ...$documents): Facts
    {
        return self::factsReadingVersions($conclusions, array_fill_keys(array_keys($conclusions), 1), ...$documents);
    }

    /**
     * @param array<string, AgentReviewConclusion> $conclusions
     * @param array<string, int>                   $versions
     */
    private static function factsReadingVersions(array $conclusions, array $versions, DocumentFacts ...$documents): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(documents: array_values($documents)),
            provided: [DocumentReviewConclusionFacts::class => new DocumentReviewConclusionFacts($conclusions, $versions)],
        );
    }
}
