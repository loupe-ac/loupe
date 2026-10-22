<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\PauseFacts;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\Workflow\RunNeedsPersonFacts;
use App\Module\Inbox\Workflow\AgentReviewOpenFacts;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;
use App\Module\Workflow\Contract\PullRequestFacts;
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
        self::assertSame([], $this->writtenInbox, 'an empty inbox set still tells that this version read the card');
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

    public function test_the_label_parameters_come_from_the_evidence_and_the_start_time_stays_the_stored_one(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('paused', 'stuck', ['card.paused' => []])]);
        $pausedAt = new \DateTimeImmutable('2026-10-01 11:00:00');
        $storedAt = new \DateTimeImmutable('2026-10-01 10:00:00');
        $stored = [new StoredMarker('paused', MarkerKind::Stuck, 'l', null, [], $storedAt)];

        $this->pass($template, FactsMother::facts(provided: [PauseFacts::class => new PauseFacts(new PauseView(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'move-refused', 'rule', PauseKind::Retries, $pausedAt))]), $stored);

        self::assertEquals($storedAt, $this->written('paused')->since);
        self::assertSame(['%reason%' => 'move-refused'], $this->written('paused')->params);
        self::assertNull($this->written('paused')->subject, 'the board set names no thing');
    }

    public function test_the_stored_time_of_the_same_key_is_kept_else_the_pass_time_counts(): void
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

    public function test_a_listed_marker_stores_one_inbox_entry_for_each_pull_request_that_makes_it_true_and_one_board_entry(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('open', 'needs-you', ['pr.open' => []]), self::marker('plain', 'waiting', ['pr.open' => []])], ['open']);
        [$first, $second] = [self::openPullRequest(1, 'aaa'), self::openPullRequest(2, 'bbb')];

        $this->pass($template, FactsMother::facts(pullRequest: $first, pullRequests: [$first, $second]));

        self::assertSame(['open', 'plain'], array_map(static fn (StoredMarker $marker): string => $marker->key, $this->written ?? []));
        self::assertSame(['open', 'open'], array_map(static fn (StoredMarker $marker): string => $marker->key, $this->writtenInbox ?? []));
        self::assertSame([['%number%' => 1], ['%number%' => 2]], array_map(static fn (StoredMarker $marker): array => $marker->params, $this->writtenInbox ?? []));
        self::assertEquals(new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $first->id, 'head' => 'aaa']), $this->writtenInbox[0]->subject ?? null);
        self::assertEquals(new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $second->id, 'head' => 'bbb']), $this->writtenInbox[1]->subject ?? null);
    }

    public function test_a_pull_request_that_does_not_make_the_condition_true_has_no_inbox_entry(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('failing', 'stuck', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]])], ['failing']);
        $passing = self::openPullRequest(1, 'aaa');
        $failing = self::openPullRequest(2, 'bbb', ChecksState::Failed);

        $this->pass($template, FactsMother::facts(pullRequest: $passing, pullRequests: [$passing, $failing]));

        self::assertSame([['%number%' => 2]], array_map(static fn (StoredMarker $marker): array => $marker->params, $this->writtenInbox ?? []));
        self::assertSame(['%number%' => 2], $this->written('failing')->params);
    }

    public function test_an_inbox_entry_keeps_the_start_time_of_the_stored_entry_with_the_same_subject_and_a_new_thing_starts_now(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('open', 'needs-you', ['pr.open' => []])], ['open']);
        [$first, $second] = [self::openPullRequest(1, 'aaa'), self::openPullRequest(2, 'bbb')];
        $storedAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $storedInbox = [$this->entry('open', $first, 'aaa', $storedAt)];

        $this->pass($template, FactsMother::facts(pullRequest: $first, pullRequests: [$first, $second]), [], $storedInbox);

        self::assertEquals([$storedAt, self::noon()], array_map(static fn (StoredMarker $marker): \DateTimeImmutable => $marker->since, $this->writtenInbox ?? []));
        self::assertEquals($storedAt, $this->written('open')->since, 'the board entry takes the start time of the first inbox entry');
    }

    public function test_a_new_head_commit_is_a_new_thing_with_a_new_start_time(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('open', 'needs-you', ['pr.open' => []])], ['open']);
        $pullRequest = self::openPullRequest(1, 'bbb');
        $storedInbox = [$this->entry('open', $pullRequest, 'aaa', new \DateTimeImmutable('2026-10-01 09:00:00'))];

        $this->pass($template, FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest]), [], $storedInbox);

        self::assertEquals(self::noon(), $this->writtenInbox[0]->since ?? null);
    }

    public function test_the_first_entry_of_a_card_that_this_version_has_not_read_takes_the_age_of_the_board_entry(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('open', 'needs-you', ['pr.open' => []])], ['open']);
        [$first, $second] = [self::openPullRequest(1, 'aaa'), self::openPullRequest(2, 'bbb')];
        $boardAt = new \DateTimeImmutable('2026-10-01 08:00:00');
        $board = [new StoredMarker('open', MarkerKind::NeedsYou, 'l', null, [], $boardAt)];

        $this->pass($template, FactsMother::facts(pullRequest: $first, pullRequests: [$first, $second]), $board, null);
        self::assertEquals([$boardAt, self::noon()], array_map(static fn (StoredMarker $marker): \DateTimeImmutable => $marker->since, $this->writtenInbox ?? []));

        $this->pass($template, FactsMother::facts(pullRequest: $first, pullRequests: [$first, $second]), $board, $this->writtenInbox);
        self::assertEquals([$boardAt, self::noon()], array_map(static fn (StoredMarker $marker): \DateTimeImmutable => $marker->since, $this->writtenInbox ?? []), 'a card that this version has read does not look at the board entry again');
    }

    public function test_a_marker_that_is_not_listed_stores_no_inbox_entry(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('open', 'needs-you', ['pr.open' => []])]);
        $pullRequest = self::openPullRequest(1, 'aaa');

        $this->pass($template, FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest]));

        self::assertSame(['open'], array_map(static fn (StoredMarker $marker): string => $marker->key, $this->written ?? []));
        self::assertSame([], $this->writtenInbox);
    }

    public function test_a_paused_card_stores_an_inbox_entry_that_names_the_pause(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('paused', 'stuck', ['card.paused' => []])], ['paused']);
        $pause = new PauseView(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'move-refused', 'rule', PauseKind::Retries, new \DateTimeImmutable('2026-10-01 11:00:00'));

        $this->pass($template, FactsMother::facts(provided: [PauseFacts::class => new PauseFacts($pause)]));

        self::assertEquals(new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => (string) $pause->id]), $this->writtenInbox[0]->subject ?? null);
        self::assertEquals(self::noon(), $this->writtenInbox[0]->since ?? null);
    }

    public function test_a_listed_marker_with_a_pull_request_leaf_other_than_open_names_each_pull_request(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('failing', 'stuck', ['pr.checks_failed' => []])], ['failing']);
        [$first, $second] = [self::openPullRequest(1, 'aaa', ChecksState::Failed), self::openPullRequest(2, 'bbb', ChecksState::Failed)];

        $this->pass($template, FactsMother::facts(pullRequest: $first, pullRequests: [$first, $second]));

        self::assertEquals([
            new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $first->id, 'head' => 'aaa']),
            new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $second->id, 'head' => 'bbb']),
        ], array_map(static fn (StoredMarker $marker): ?MarkerSubject => $marker->subject, $this->writtenInbox ?? []));
    }

    public function test_a_run_that_needs_a_person_stores_an_inbox_entry_that_names_the_run(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('needs-person', 'needs-you', ['card.run.needs_person' => []])], ['needs-person']);
        $run = new RunNeedsPersonFacts('run-1', WorkerRunState::Blocked, new \DateTimeImmutable('2026-10-01 11:00:00'), null);

        $this->pass($template, FactsMother::facts(provided: [RunNeedsPersonFacts::class => $run]));

        self::assertEquals(new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => 'run-1']), $this->writtenInbox[0]->subject ?? null);
    }

    public function test_a_listed_marker_that_reads_a_named_fact_names_the_pull_requests_of_its_leaves(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate(
            [self::marker('failing', 'stuck', ['fact.pr-failing' => []])],
            ['failing'],
            ['pr-failing' => ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]]],
        );
        [$first, $second] = [self::openPullRequest(1, 'aaa', ChecksState::Failed), self::openPullRequest(2, 'bbb', ChecksState::Failed)];

        $this->pass($template, FactsMother::facts(pullRequest: $first, pullRequests: [$first, $second]));

        self::assertEquals([
            new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $first->id, 'head' => 'aaa']),
            new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $second->id, 'head' => 'bbb']),
        ], array_map(static fn (StoredMarker $marker): ?MarkerSubject => $marker->subject, $this->writtenInbox ?? []));
    }

    public function test_a_marker_with_no_subject_leaf_gets_the_card_as_its_thing_for_one_spell(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('bug', 'waiting', ['card.type' => ['type' => 'bug']])], ['bug']);
        $facts = FactsMother::facts(card: FactsMother::card(type: 'bug'));

        $this->pass($template, $facts);
        $entry = $this->writtenInbox[0] ?? throw new \LogicException('The pass stored no inbox entry.');
        self::assertSame(MarkerSubjectType::Card, $entry->subject?->type);
        self::assertEquals(self::noon(), $entry->since);
        self::assertSame(self::noon()->format(\DATE_ATOM), $entry->subject->ids['spell'] ?? null);

        $later = FactsMother::facts(card: FactsMother::card(type: 'bug'), now: new \DateTimeImmutable('2026-10-01 13:00:00'));
        $this->pass($template, $later, [], [$entry]);
        self::assertEquals($entry->subject, $this->writtenInbox[0]->subject ?? null, 'the spell holds while the marker stays true');
        self::assertEquals(self::noon(), $this->writtenInbox[0]->since ?? null);

        $this->pass($template, $later, [], []);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 13:00:00'), $this->writtenInbox[0]->since ?? null, 'a marker that turned off and on starts a new spell');
    }

    public function test_a_marker_whose_leaves_name_two_subject_types_gets_the_card_as_its_thing(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('either', 'stuck', ['any' => [['pr.open' => []], ['card.paused' => []]]])], ['either']);
        $pullRequest = self::openPullRequest(1, 'aaa');

        $this->pass($template, FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest], provided: [PauseFacts::class => new PauseFacts(null)]));

        self::assertCount(1, $this->writtenInbox ?? []);
        self::assertSame(MarkerSubjectType::Card, ($this->writtenInbox[0] ?? throw new \LogicException('The pass stored no inbox entry.'))->subject?->type);
    }

    public function test_a_listed_marker_stores_one_inbox_entry_for_each_document_in_review_that_makes_it_true(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('review', 'needs-you', ['card.document.in_review' => ['tag' => 'design']])], ['review']);
        $documents = new DocumentsFacts([
            new DocumentFacts(['design'], 'in-review', 'one', 'First', new \DateTimeImmutable('2026-10-01 09:00:00'), 2),
            new DocumentFacts(['design'], 'in-review', 'two', 'Second', new \DateTimeImmutable('2026-10-01 10:00:00'), 5),
            new DocumentFacts(['plan'], 'in-review', 'three', 'Other tag', null, 1),
            new DocumentFacts(['design'], 'approved', 'four', 'Approved', null, 1),
        ]);

        $this->pass($template, FactsMother::facts(provided: [DocumentsFacts::class => $documents]));

        self::assertEquals([
            new MarkerSubject(MarkerSubjectType::Document, ['document' => 'one', 'version' => '2']),
            new MarkerSubject(MarkerSubjectType::Document, ['document' => 'two', 'version' => '5']),
        ], array_map(static fn (StoredMarker $marker): ?MarkerSubject => $marker->subject, $this->writtenInbox ?? []));
        self::assertSame([['%title%' => 'First'], ['%title%' => 'Second']], array_map(static fn (StoredMarker $marker): array => $marker->params, $this->writtenInbox ?? []));
        self::assertSame(['%title%' => 'First'], $this->written('review')->params, 'the board entry keeps the document that waited longest');
    }

    public function test_a_document_marker_reads_each_bound_document_alone(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([self::marker('review', 'needs-you', ['all' => [['card.document.in_review' => ['tag' => 'design']], ['not' => ['card.document.agent_review_open' => []]]]])], ['review']);
        $documents = new DocumentsFacts([new DocumentFacts(['design'], 'in-review', 'one', 'First', null, 1), new DocumentFacts(['design'], 'in-review', 'two', 'Second', null, 1)]);

        $this->pass($template, FactsMother::facts(provided: [DocumentsFacts::class => $documents, AgentReviewOpenFacts::class => new AgentReviewOpenFacts(['one'])]));

        self::assertEquals([new MarkerSubject(MarkerSubjectType::Document, ['document' => 'two', 'version' => '1'])], array_map(static fn (StoredMarker $marker): ?MarkerSubject => $marker->subject, $this->writtenInbox ?? []), 'the document under agent review opens no entry');
    }

    public function test_a_listed_fallback_stores_inbox_entries_only_while_the_board_shows_it(): void
    {
        self::bootKernel();
        $template = $this->markerTemplate([
            self::marker('busy', 'working', ['card.type' => ['type' => 'bug']]),
            self::marker('quiet', 'stuck', self::ALWAYS, fallback: true),
            self::marker('later', 'stuck', self::ALWAYS, fallback: true),
            self::marker('chip', 'chip', self::ALWAYS),
        ], ['quiet', 'later', 'chip']);

        $this->pass($template, FactsMother::facts());
        self::assertSame(['quiet', 'chip'], array_map(static fn (StoredMarker $marker): string => $marker->key, $this->writtenInbox ?? []), 'only the fallback that the board shows, in template order');

        $this->pass($template, FactsMother::facts(card: FactsMother::card(type: 'bug')));
        self::assertSame(['chip'], array_map(static fn (StoredMarker $marker): string => $marker->key, $this->writtenInbox ?? []), 'a state marker hides the fallback on the board and in the inbox');
    }

    private static function openPullRequest(int $number, string $head, ChecksState $checks = ChecksState::None): PullRequestFacts
    {
        return FactsMother::pullRequest(checks: $checks, id: Uuid::v7(), headSha: $head, number: $number);
    }

    private function entry(string $key, PullRequestFacts $pullRequest, string $head, \DateTimeImmutable $since): StoredMarker
    {
        return new StoredMarker($key, MarkerKind::NeedsYou, 'marker.'.$key, null, ['%number%' => $pullRequest->number ?? 0], $since, null, new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => (string) $pullRequest->id, 'head' => $head]));
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
