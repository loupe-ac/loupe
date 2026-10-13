<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\PauseFacts;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;
use App\Module\Workflow\Contract\StoredMarker;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MarkerPassTest extends KernelTestCase
{
    use MarkerScenario;

    private const array ALWAYS = ['all' => []];

    private const array NEVER = ['not' => ['all' => []]];

    public function test_the_kinds_are_stored_in_the_order_of_their_precedence_and_each_kind_in_template_order(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('chip', 'chip', self::ALWAYS),
            self::marker('waiting', 'waiting', self::ALWAYS),
            self::marker('working-b', 'working', self::ALWAYS),
            self::marker('working-a', 'working', self::ALWAYS),
            self::marker('needs', 'needs-you', self::ALWAYS),
            self::marker('stuck', 'stuck', self::ALWAYS),
        ]);

        $keys = $this->passKeys($template, FactsMother::facts());

        self::assertSame(['stuck', 'needs', 'working-b', 'working-a', 'waiting', 'chip'], $keys);
        self::assertSame([MarkerKind::Stuck, MarkerKind::NeedsYou, MarkerKind::Working, MarkerKind::Working, MarkerKind::Waiting, MarkerKind::Chip], array_map(static fn (StoredMarker $marker): MarkerKind => $marker->kind, $this->written ?? []));
    }

    public function test_a_marker_whose_condition_is_false_is_not_stored(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('yes', 'working', self::ALWAYS),
            self::marker('no', 'working', self::NEVER),
        ]);

        self::assertSame(['yes'], $this->passKeys($template, FactsMother::facts()));
    }

    public function test_a_fallback_counts_only_when_no_marker_of_a_state_kind_matches(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('busy', 'working', ['card.type' => ['type' => 'bug']]),
            self::marker('quiet', 'stuck', self::ALWAYS, fallback: true),
            self::marker('chip', 'chip', self::ALWAYS),
        ]);

        self::assertSame(['quiet', 'chip'], $this->passKeys($template, FactsMother::facts()), 'a chip does not hide a fallback');
        self::assertSame(['busy', 'chip'], $this->passKeys($template, FactsMother::facts(card: FactsMother::card(type: 'bug'))));
    }

    public function test_only_the_first_matching_fallback_counts(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('first', 'stuck', self::NEVER, fallback: true),
            self::marker('second', 'stuck', self::ALWAYS, fallback: true),
            self::marker('third', 'stuck', self::ALWAYS, fallback: true),
        ]);

        self::assertSame(['second'], $this->passKeys($template, FactsMother::facts()));
    }

    public function test_a_marker_with_slots_applies_in_those_slots_and_one_without_applies_in_every_open_column(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('in-two', 'working', self::ALWAYS, slots: ['two']),
            self::marker('anywhere', 'waiting', self::ALWAYS),
        ]);

        self::assertSame(['anywhere'], $this->passKeys($template, FactsMother::facts(card: FactsMother::card(slot: 'one'))));
        self::assertSame(['in-two', 'anywhere'], $this->passKeys($template, FactsMother::facts(card: FactsMother::card(slot: 'two'))));
        self::assertSame(['anywhere'], $this->passKeys($template, FactsMother::facts(card: FactsMother::card(slot: '@backlog'))));
        self::assertSame(['anywhere'], $this->passKeys($template, FactsMother::facts(card: FactsMother::card(slot: null))));
    }

    public function test_a_card_in_a_terminal_column_stores_an_empty_set_and_no_due_time(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('anywhere', 'waiting', self::ALWAYS)]);
        $stored = [new StoredMarker('anywhere', MarkerKind::Waiting, 'l', null, [], self::noon())];

        $keys = $this->passKeys($template, FactsMother::facts(card: FactsMother::card(slot: '@terminal')), $stored);

        self::assertSame([], $keys);
        self::assertSame([], $this->written);
        self::assertNull($this->writtenDueAt);
    }

    public function test_a_condition_that_cannot_be_read_does_not_match_and_the_others_still_do(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('paused', 'stuck', ['card.paused' => []]),
            self::marker('yes', 'working', self::ALWAYS),
        ]);
        $facts = FactsMother::facts(provided: [PauseFacts::class => new Unreadable(UnreadableKind::Failed, 'workflow.source.board')]);

        self::assertSame(['yes'], $this->passKeys($template, $facts));
    }

    public function test_the_label_parameters_come_from_every_true_leaf_in_template_order(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('failing', 'stuck', ['all' => [['pr.open' => []], ['pr.checks_failed' => []], ['not' => ['pr.conflicting' => []]]]]),
        ]);
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed, number: 7));

        $this->pass($template, $facts);

        self::assertSame(['%number%' => 7], $this->written('failing')->params);
        self::assertSame('marker.failing', $this->written('failing')->label);
        self::assertSame('marker.failing.remedy', $this->written('failing')->remedy);
    }

    public function test_a_marker_that_reads_the_pull_request_takes_the_first_open_one_that_makes_it_true(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('failing', 'stuck', ['pr.checks_failed' => []])]);
        $passing = FactsMother::pullRequest(checks: ChecksState::Passed, number: 1);
        $failing = FactsMother::pullRequest(checks: ChecksState::Failed, number: 2);

        $this->pass($template, FactsMother::facts(pullRequest: $passing, pullRequests: [$passing, $failing]));

        self::assertSame(['%number%' => 2], $this->written('failing')->params);
    }

    public function test_the_start_time_is_the_one_the_evidence_gives(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('paused', 'stuck', ['card.paused' => []])]);
        $pausedAt = new \DateTimeImmutable('2026-10-01 11:00:00');
        $stored = [new StoredMarker('paused', MarkerKind::Stuck, 'l', null, [], new \DateTimeImmutable('2026-10-01 10:00:00'))];

        $this->pass($template, FactsMother::facts(provided: [PauseFacts::class => new PauseFacts(new PauseView(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'move-refused', 'rule', PauseKind::Retries, $pausedAt))]), $stored);

        self::assertEquals($pausedAt, $this->written('paused')->since);
        self::assertSame(['%reason%' => 'move-refused'], $this->written('paused')->params);
    }

    public function test_with_no_evidence_time_the_stored_time_of_the_same_key_is_kept_else_the_pass_time_counts(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('bug', 'waiting', ['card.type' => ['type' => 'bug']])]);
        $facts = FactsMother::facts(card: FactsMother::card(type: 'bug'));
        $storedAt = new \DateTimeImmutable('2026-10-01 10:00:00');

        $this->pass($template, $facts, [new StoredMarker('bug', MarkerKind::Waiting, 'l', null, [], $storedAt)]);
        self::assertEquals($storedAt, $this->written('bug')->since);

        $this->pass($template, $facts, [new StoredMarker('other', MarkerKind::Waiting, 'l', null, [], $storedAt)]);
        self::assertEquals(self::noon(), $this->written('bug')->since);

        $this->pass($template, $facts);
        self::assertEquals(self::noon(), $this->written('bug')->since);
    }

    public function test_the_due_time_is_the_earliest_change_of_a_timed_condition_whether_it_is_true_or_false(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('slow', 'stuck', ['card.quiet_for' => ['delay' => 60]]),
            self::marker('fast', 'stuck', ['card.quiet_for' => ['delay' => 30]]),
            self::marker('plain', 'working', self::ALWAYS),
        ]);
        $facts = FactsMother::facts(provided: [CardActivityFacts::class => new CardActivityFacts(new \DateTimeImmutable('2026-10-01 11:50:00'))]);

        $keys = $this->passKeys($template, $facts);

        self::assertSame(['plain'], $keys);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:20:00'), $this->writtenDueAt);
    }

    public function test_a_timed_condition_that_already_holds_leaves_no_due_time(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('quiet', 'stuck', ['card.quiet_for' => ['delay' => 30]])]);
        $facts = FactsMother::facts(provided: [CardActivityFacts::class => new CardActivityFacts(new \DateTimeImmutable('2026-10-01 11:00:00'))]);

        self::assertSame(['quiet'], $this->passKeys($template, $facts));
        self::assertNull($this->writtenDueAt);
    }

    public function test_the_due_time_counts_every_open_pull_request_and_not_only_the_bound_one(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('ready', 'stuck', ['pr.ready_for' => ['delay' => 30]])]);
        $idle = FactsMother::pullRequest(number: 1);
        $ready = FactsMother::pullRequest(number: 2, readyToMerge: true, readySince: new \DateTimeImmutable('2026-10-01 11:50:00'));

        $keys = $this->passKeys($template, FactsMother::facts(pullRequest: $idle, pullRequests: [$idle, $ready]));

        self::assertSame([], $keys);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:20:00'), $this->writtenDueAt);
    }

    private static function noon(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    /**
     * @param array<string, mixed> $when
     * @param list<string>         $slots
     *
     * @return array<string, mixed>
     */
    private static function marker(string $key, string $kind, array $when, bool $fallback = false, array $slots = []): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'label' => 'marker.'.$key,
            'remedy' => 'marker.'.$key.'.remedy',
            ...($fallback ? ['fallback' => true] : []),
            ...([] === $slots ? [] : ['slots' => $slots]),
            'when' => $when,
        ];
    }
}
