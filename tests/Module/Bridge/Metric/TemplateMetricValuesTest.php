<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Bridge\Metric\TemplateMetricValues;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use PHPUnit\Framework\TestCase;

final class TemplateMetricValuesTest extends TestCase
{
    public function test_a_duration_starts_at_the_first_start_event_and_ends_at_the_last_end_event_after_it(): void
    {
        $milestones = [
            new CardMilestone(DurationEvent::PR_MERGED, self::at('06:00')),
            new CardMilestone(DurationEvent::PR_OPENED, self::at('08:00')),
            new CardMilestone(DurationEvent::PR_OPENED, self::at('10:00')),
            new CardMilestone(DurationEvent::PR_MERGED, self::at('12:00')),
            new CardMilestone(DurationEvent::PR_MERGED, self::at('14:00')),
        ];

        self::assertSame(6.0, TemplateMetricValues::hours($milestones, self::duration(DurationEvent::PR_OPENED, DurationEvent::PR_MERGED)));
    }

    public function test_a_duration_that_starts_at_the_last_event_takes_the_last_occurrence(): void
    {
        $milestones = [
            new CardMilestone(DurationEvent::ARRIVED, self::at('08:00'), 'review'),
            new CardMilestone(DurationEvent::ARRIVED, self::at('10:00'), 'review'),
            new CardMilestone(DurationEvent::LEFT, self::at('09:00'), 'review'),
            new CardMilestone(DurationEvent::LEFT, self::at('10:30'), 'review'),
            new CardMilestone(DurationEvent::LEFT, self::at('11:00'), 'other'),
        ];
        $first = self::duration(DurationEvent::ARRIVED, DurationEvent::LEFT, 'review', fromLast: false);
        $last = self::duration(DurationEvent::ARRIVED, DurationEvent::LEFT, 'review', fromLast: true);

        self::assertSame(2.5, TemplateMetricValues::hours($milestones, $first));
        self::assertSame(0.5, TemplateMetricValues::hours($milestones, $last));
    }

    public function test_a_bounce_through_the_agent_review_reads_from_the_first_open_to_the_last_pass(): void
    {
        $milestones = [
            new CardMilestone(DurationEvent::PR_OPENED, self::at('09:00')),
            new CardMilestone(DurationEvent::AGENT_REVIEW_PASSED, self::at('11:00')),
            new CardMilestone(DurationEvent::AGENT_REVIEW_PASSED, self::at('15:00')),
            new CardMilestone(DurationEvent::READY_TO_MERGE, self::at('16:00')),
        ];
        $toAgentPass = self::duration(DurationEvent::PR_OPENED, DurationEvent::AGENT_REVIEW_PASSED);
        $waitingForApproval = self::duration(DurationEvent::AGENT_REVIEW_PASSED, DurationEvent::READY_TO_MERGE, fromLast: true);

        self::assertSame(6.0, TemplateMetricValues::hours($milestones, $toAgentPass));
        self::assertSame(1.0, TemplateMetricValues::hours($milestones, $waitingForApproval));
    }

    public function test_a_card_with_no_start_or_no_end_after_the_start_has_no_value(): void
    {
        $metric = self::duration(DurationEvent::PR_OPENED, DurationEvent::PR_MERGED);

        self::assertNull(TemplateMetricValues::hours([], $metric));
        self::assertNull(TemplateMetricValues::hours([new CardMilestone(DurationEvent::PR_OPENED, self::at('08:00'))], $metric));
        self::assertNull(TemplateMetricValues::hours([new CardMilestone(DurationEvent::PR_MERGED, self::at('08:00'))], $metric));
        self::assertNull(TemplateMetricValues::hours([
            new CardMilestone(DurationEvent::PR_MERGED, self::at('06:00')),
            new CardMilestone(DurationEvent::PR_OPENED, self::at('08:00')),
        ], $metric));
    }

    public function test_a_duration_with_two_starts_begins_at_the_earliest_of_them(): void
    {
        $metric = new TemplateMetric('t', 'T', 'T.', MetricType::Duration, from: new DurationEvent(DurationEvent::ARRIVED, 'build'), to: new DurationEvent(DurationEvent::PR_MERGED), alsoFrom: [new DurationEvent(DurationEvent::PR_OPENED)]);
        $end = new CardMilestone(DurationEvent::PR_MERGED, self::at('12:00'));
        $arrived = new CardMilestone(DurationEvent::ARRIVED, self::at('08:00'), 'build');
        $opened = new CardMilestone(DurationEvent::PR_OPENED, self::at('10:00'));

        self::assertSame(4.0, TemplateMetricValues::hours([$arrived, $opened, $end], $metric));
        self::assertSame(2.0, TemplateMetricValues::hours([$opened, $end], $metric));
        self::assertSame(4.0, TemplateMetricValues::hours([$arrived, $end], $metric));
        self::assertNull(TemplateMetricValues::hours([$end], $metric));
    }

    public function test_an_end_at_the_same_moment_as_the_start_counts_as_zero_hours(): void
    {
        $milestones = [
            new CardMilestone(DurationEvent::READY_TO_MERGE, self::at('08:00')),
            new CardMilestone(DurationEvent::AGENT_REVIEW_PASSED, self::at('08:00')),
        ];

        self::assertSame(0.0, TemplateMetricValues::hours($milestones, self::duration(DurationEvent::AGENT_REVIEW_PASSED, DurationEvent::READY_TO_MERGE)));
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-01 '.$time.':00');
    }

    private static function duration(string $from, string $to, ?string $slot = null, bool $fromLast = false): TemplateMetric
    {
        return new TemplateMetric('t', 'T', 'T.', MetricType::Duration, from: new DurationEvent($from, $slot), fromLast: $fromLast, to: new DurationEvent($to, $slot));
    }
}
