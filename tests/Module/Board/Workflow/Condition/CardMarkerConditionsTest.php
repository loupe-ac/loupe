<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\Condition\CardDocumentInReview;
use App\Module\Board\Workflow\Condition\CardPaused;
use App\Module\Board\Workflow\Condition\CardQuietFor;
use App\Module\Board\Workflow\Condition\PullRequestReadyFor;
use App\Module\Board\Workflow\PauseFacts;
use App\Module\Board\Workflow\StuckDelayFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CardMarkerConditionsTest extends TestCase
{
    private const string NOW = '2026-10-01 12:00:00';

    public function test_a_card_with_an_active_pause_is_paused_since_the_pause(): void
    {
        $since = new \DateTimeImmutable('2026-10-01 09:00:00');
        $facts = self::facts([PauseFacts::class => new PauseFacts(self::pause('checks keep failing', $since))]);

        self::assertTrue(new CardPaused()->evaluate($facts, []));
        self::assertEquals(new Evidence($since, ['%reason%' => 'checks keep failing']), new CardPaused()->evidence($facts, []));
    }

    public function test_a_card_with_no_active_pause_is_not_paused(): void
    {
        $facts = self::facts([PauseFacts::class => new PauseFacts(null)]);

        self::assertFalse(new CardPaused()->evaluate($facts, []));
        self::assertNull(new CardPaused()->evidence($facts, []));
    }

    public function test_a_document_with_the_tag_in_review_matches(): void
    {
        $condition = new CardDocumentInReview();
        $params = ['tag' => 'tech-design'];

        self::assertTrue($condition->evaluate(self::documents(self::document(['tech-design'], 'in-review')), $params));
        self::assertFalse($condition->evaluate(self::documents(self::document(['tech-design'], 'approved')), $params));
        self::assertFalse($condition->evaluate(self::documents(self::document(['product-design'], 'in-review')), $params));
        self::assertFalse($condition->evaluate(self::documents(), $params));
        self::assertSame('tech-design', $condition->documentTag($params));
    }

    public function test_the_document_in_review_that_waited_longest_gives_the_evidence(): void
    {
        $facts = self::documents(
            self::document(['tech-design'], 'in-review', 'No version', null),
            self::document(['tech-design'], 'in-review', 'Later', '2026-10-01 10:00:00'),
            self::document(['tech-design'], 'approved', 'Approved', '2026-10-01 07:00:00'),
            self::document(['product-design'], 'in-review', 'Other tag', '2026-10-01 06:00:00'),
            self::document(['tech-design'], 'in-review', 'Earlier', '2026-10-01 08:00:00'),
        );

        self::assertEquals(
            new Evidence(new \DateTimeImmutable('2026-10-01 08:00:00'), ['%title%' => 'Earlier']),
            new CardDocumentInReview()->evidence($facts, ['tag' => 'tech-design']),
        );
    }

    public function test_a_document_in_review_with_no_version_still_gives_its_title(): void
    {
        $facts = self::documents(self::document(['tech-design'], 'in-review', 'No version', null));

        self::assertEquals(new Evidence(null, ['%title%' => 'No version']), new CardDocumentInReview()->evidence($facts, ['tag' => 'tech-design']));
        self::assertNull(new CardDocumentInReview()->evidence(self::documents(), ['tag' => 'tech-design']));
    }

    public function test_a_card_is_quiet_once_the_delay_after_its_newest_event_passed(): void
    {
        $condition = new CardQuietFor();

        self::assertTrue($condition->evaluate(self::activity('2026-10-01 11:00:00'), ['delay' => 60]));
        self::assertTrue($condition->evaluate(self::activity('2026-10-01 11:00:00'), ['delay' => 59]));
        self::assertFalse($condition->evaluate(self::activity('2026-10-01 11:00:00'), ['delay' => 61]));
        self::assertFalse($condition->evaluate(self::activity(null), ['delay' => 1]));
    }

    public function test_a_quiet_card_is_stuck_from_its_newest_event(): void
    {
        $condition = new CardQuietFor();

        self::assertEquals(new Evidence(new \DateTimeImmutable('2026-10-01 11:00:00')), $condition->evidence(self::activity('2026-10-01 11:00:00'), ['delay' => 30]));
        self::assertNull($condition->evidence(self::activity(null), ['delay' => 30]));
    }

    public function test_the_quiet_delay_ends_once_and_then_time_alone_changes_nothing(): void
    {
        $condition = new CardQuietFor();

        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:30:00'), $condition->changesAt(self::activity('2026-10-01 11:00:00'), ['delay' => 90]));
        self::assertNull($condition->changesAt(self::activity('2026-10-01 11:00:00'), ['delay' => 60]));
        self::assertNull($condition->changesAt(self::activity(null), ['delay' => 60]));
    }

    public function test_with_no_delay_the_quiet_condition_reads_the_stuck_delay_of_the_project(): void
    {
        $condition = new CardQuietFor();
        $facts = self::activity('2026-10-01 11:00:00', stuckDelay: 90);

        self::assertSame([CardActivityFacts::class, StuckDelayFacts::class], $condition->reads([]));
        self::assertSame([CardActivityFacts::class], $condition->reads(['delay' => 5]));
        self::assertFalse($condition->evaluate($facts, []));
        self::assertTrue($condition->evaluate($facts, ['delay' => 60]));
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:30:00'), $condition->changesAt($facts, []));
        self::assertSame('workflow.waiting.card_quiet_for_stuck_delay', $condition->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_quiet_for', $condition->waitingFor(['delay' => 5], negated: true)->getMessage());
    }

    public function test_with_no_delay_ready_for_reads_the_stuck_delay_of_the_project(): void
    {
        $condition = new PullRequestReadyFor();
        $pullRequest = FactsMother::pullRequest(number: 7, readyToMerge: true, readySince: new \DateTimeImmutable('2026-10-01 10:00:00'));
        $facts = static fn (int $minutes): Facts => FactsMother::facts(
            pullRequest: $pullRequest,
            pullRequests: [$pullRequest],
            now: new \DateTimeImmutable(self::NOW),
            provided: [StuckDelayFacts::class => new StuckDelayFacts($minutes)],
        );

        self::assertContains(StuckDelayFacts::class, $condition->reads([]));
        self::assertNotContains(StuckDelayFacts::class, $condition->reads(['delay' => 5]));
        self::assertTrue($condition->evaluate($facts(120), []));
        self::assertFalse($condition->evaluate($facts(121), []));
        self::assertEquals(new Evidence(new \DateTimeImmutable('2026-10-01 10:00:00'), ['%number%' => 7]), $condition->evidence($facts(30), []));
        self::assertEquals(new \DateTimeImmutable('2026-10-01 13:00:00'), $condition->changesAt($facts(180), []));
        self::assertSame('workflow.waiting.pr_ready_for_stuck_delay', $condition->waitingFor([])->getMessage());
        self::assertSame(['%delay%' => '5'], $condition->waitingFor(['delay' => 5])->getParameters());
    }

    /** @param array<class-string, object> $provided */
    private static function facts(array $provided): Facts
    {
        return FactsMother::facts(now: new \DateTimeImmutable(self::NOW), provided: $provided);
    }

    private static function documents(DocumentFacts ...$documents): Facts
    {
        return FactsMother::facts(card: FactsMother::card(documents: array_values($documents)), now: new \DateTimeImmutable(self::NOW));
    }

    /** @param list<string> $tags */
    private static function document(array $tags, string $status, string $title = 'Design', ?string $versionAt = '2026-10-01 09:00:00'): DocumentFacts
    {
        return new DocumentFacts($tags, $status, Uuid::v7()->toRfc4122(), $title, null === $versionAt ? null : new \DateTimeImmutable($versionAt));
    }

    private static function activity(?string $newestEventAt, int $stuckDelay = 60): Facts
    {
        return self::facts([
            CardActivityFacts::class => new CardActivityFacts(null === $newestEventAt ? null : new \DateTimeImmutable($newestEventAt)),
            StuckDelayFacts::class => new StuckDelayFacts($stuckDelay),
        ]);
    }

    private static function pause(string $reason, \DateTimeImmutable $since): PauseView
    {
        return new PauseView(Uuid::v7(), Uuid::v7(), Uuid::v7(), $reason, 'rule', PauseKind::Rule, $since);
    }
}
