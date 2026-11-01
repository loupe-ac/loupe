<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\View;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\View\CardHistoryEntry;
use App\Module\Board\View\CardHistoryRun;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

final class CardHistoryEntryTest extends TestCase
{
    private const array BACKLOG = ['id' => 'b', 'label' => 'board.column.backlog', 'slug' => 'backlog'];
    private const array REVIEW = ['id' => 'r', 'label' => 'Review', 'slug' => 'review'];
    private const string RUN_ID = '0199a0c4-8d2e-7c6b-9f1e-3a5b7c9d1e2f';

    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->user = new User(fullName: 'Riley Chen', email: 'riley@example.com');
    }

    public function test_a_creation_names_the_actor_and_the_column(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Created, Actor::Human, $this->user, ['column' => self::BACKLOG]));

        self::assertSame('lucide:plus', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.created', [
            '%actor%' => 'Riley Chen',
            '%column%' => new TranslatableMessage('board.column.backlog'),
        ]), $entry->sentence);
        self::assertNull($entry->cause);
        self::assertNull($entry->run);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:00'), $entry->occurredAt);
    }

    public function test_a_move_names_both_columns_and_has_no_cause_line_without_a_cause(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Moved, Actor::Human, $this->user, ['from' => self::BACKLOG, 'to' => self::REVIEW, 'cause' => null]));

        self::assertSame('lucide:arrow-right', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.moved', [
            '%actor%' => 'Riley Chen',
            '%from%' => new TranslatableMessage('board.column.backlog'),
            '%to%' => new TranslatableMessage('Review'),
        ]), $entry->sentence);
        self::assertNull($entry->cause);
    }

    /** @param array<string, mixed> $cause */
    #[DataProvider('causes')]
    public function test_a_move_by_the_app_says_why(array $cause, ?TranslatableMessage $expected): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Moved, Actor::System, null, ['from' => self::BACKLOG, 'to' => self::REVIEW, 'cause' => $cause]));

        self::assertSame('board.card.history.moved', $entry->sentence->getMessage());
        self::assertEquals($expected, $entry->cause);
    }

    /** @return iterable<string, array{array<string, mixed>, ?TranslatableMessage}> */
    public static function causes(): iterable
    {
        yield 'checks passed' => [['type' => 'checks-passed', 'pullRequest' => 12], new TranslatableMessage('board.card.history.cause.checks_passed', ['%pr%' => 12])];
        yield 'document approved' => [['type' => 'document-approved', 'document' => 'Tech design'], new TranslatableMessage('board.card.history.cause.document_approved', ['%document%' => 'Tech design'])];
        yield 'epic reconciled' => [['type' => 'epic-reconciled', 'child' => 7], new TranslatableMessage('board.card.history.cause.epic_reconciled', ['%child%' => 7])];
        yield 'unblocked' => [['type' => 'unblocked', 'blocker' => 3], new TranslatableMessage('board.card.history.cause.unblocked', ['%blocker%' => 3])];
        yield 'unblocked with no blocker' => [['type' => 'unblocked'], new TranslatableMessage('board.card.history.cause.unblocked_any')];
        yield 'abandoned' => [['type' => 'abandoned'], new TranslatableMessage('board.card.history.cause.abandoned')];
        yield 'run' => [['type' => 'run', 'run' => '01a0f000-0000-7000-8000-000000000001', 'kind' => 'implement'], new TranslatableMessage('board.card.history.cause.run', ['%kind%' => 'implement'])];
        yield 'run written with a rule name' => [['type' => 'run', 'run' => '01a0f000-0000-7000-8000-000000000001', 'rule' => 'implement'], new TranslatableMessage('board.card.history.cause.run', ['%kind%' => 'implement'])];
        yield 'run with no work kind' => [['type' => 'run', 'run' => '01a0f000-0000-7000-8000-000000000001'], new TranslatableMessage('board.card.history.cause.run_no_kind')];
        yield 'workflow rule' => [['type' => 'workflow-rule', 'rule' => 'merge-on-green'], new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'merge-on-green'])];
        yield 'column deleted' => [['type' => 'column-deleted', 'column' => 'board.column.next'], new TranslatableMessage('board.card.history.cause.column_deleted', ['%column%' => new TranslatableMessage('board.column.next')])];
        yield 'an unknown type' => [['type' => 'moon-phase'], null];
        yield 'a cause with its field missing' => [['type' => 'checks-passed'], null];
        yield 'a cause with no type' => [['pullRequest' => 12], null];
    }

    public function test_the_app_asking_for_a_fix_gives_the_pull_request_and_the_reason(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::FixRequested, Actor::System, null, ['reason' => 'checks-failed', 'pullRequest' => 42]));

        self::assertSame('lucide:wrench', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.fix_requested', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%pr%' => 42,
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.checks_failed'),
        ]), $entry->cause);
    }

    public function test_a_fix_for_the_agent_review_gives_its_own_reason(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::FixRequested, Actor::System, null, ['reason' => 'agent-review', 'pullRequest' => 42]));

        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.agent_review'),
        ]), $entry->cause);
    }

    public function test_a_stop_gives_the_pull_request_and_the_reason(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Stopped, Actor::System, null, ['reason' => 'conflict', 'pullRequest' => 42]));

        self::assertSame('lucide:ban', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.stopped', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%pr%' => 42,
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.conflict'),
        ]), $entry->cause);
    }

    public function test_a_changes_requested_reason_translates(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Stopped, Actor::System, null, ['reason' => 'changes-requested', 'pullRequest' => 42]));

        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.changes_requested'),
        ]), $entry->cause);
    }

    public function test_an_unknown_reason_leaves_no_reason_line(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::FixRequested, Actor::System, null, ['reason' => 'solar-flare', 'pullRequest' => 42]));

        self::assertSame('board.card.history.fix_requested', $entry->sentence->getMessage());
        self::assertNull($entry->cause);
    }

    public function test_a_ready_pull_request_names_its_number(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => 42]));

        self::assertSame('lucide:git-merge', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.ready_to_merge', ['%pr%' => 42]), $entry->sentence);
    }

    public function test_a_synced_pull_request_names_its_number(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Synced, Actor::System, null, ['pullRequest' => 42]));

        self::assertSame('lucide:git-compare', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.synced', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%pr%' => 42,
        ]), $entry->sentence);
    }

    public function test_a_finished_run_carries_its_work_kind_duration_and_result(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, $this->runDetail('succeeded', 192)), true);

        self::assertSame('lucide:bot', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.run_finished', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.agent', ['%name%' => 'Riley Chen']),
            '%kind%' => 'implement',
        ]), $entry->sentence);
        self::assertEquals(new CardHistoryRun(
            runId: self::RUN_ID,
            duration: '3m 12s',
            stateKey: 'bridge.worker_runs.state.succeeded',
            chipModifier: 'ok',
            interactive: false,
            command: false,
        ), $entry->run);
    }

    public function test_a_run_that_a_rule_stopped_links_to_the_run_and_names_the_rule(): void
    {
        $detail = ['runId' => self::RUN_ID, 'workKind' => 'implement', 'rule' => 'product-design-undo', 'durationSeconds' => 252];

        $entry = CardHistoryEntry::of($this->event(CardEventKind::Stopped, Actor::System, null, $detail), true);

        self::assertSame('lucide:circle-stop', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.stopped_work', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%kind%' => 'implement',
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'product-design-undo']), $entry->cause);
        self::assertEquals(new CardHistoryRun(
            runId: self::RUN_ID,
            duration: '4m 12s',
            stateKey: 'bridge.worker_runs.state.stopped',
            chipModifier: 'neutral',
            interactive: false,
            command: false,
        ), $entry->run);
        self::assertSame(self::RUN_ID, CardHistoryEntry::runIdOf($this->event(CardEventKind::Stopped, Actor::System, null, $detail)));
    }

    public function test_a_stopped_run_whose_row_was_purged_has_no_link_and_one_with_no_kind_has_a_plain_sentence(): void
    {
        $purged = CardHistoryEntry::of($this->event(CardEventKind::Stopped, Actor::System, null, ['runId' => self::RUN_ID, 'workKind' => 'implement', 'rule' => 'r']), false);
        $plain = CardHistoryEntry::of($this->event(CardEventKind::Stopped, Actor::System, null, ['runId' => self::RUN_ID, 'workKind' => null, 'rule' => 'r']), true);

        self::assertNull($purged->run?->runId);
        self::assertNull($purged->run?->duration);
        self::assertSame('board.card.history.stopped_work_no_kind', $plain->sentence->getMessage());
    }

    public function test_a_finished_command_run_says_it_is_a_command(): void
    {
        $detail = [...$this->runDetail('failed', 4), 'command' => true];

        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, $detail), true);

        self::assertTrue($entry->run?->command);
        self::assertFalse($entry->run->interactive);
    }

    public function test_a_run_that_never_started_does_not_say_the_agent_ran_it(): void
    {
        foreach (['not-started', 'skipped', 'replaced', 'dropped'] as $state) {
            $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, $this->runDetail($state, null)), true);

            self::assertEquals(new TranslatableMessage('board.card.history.run_not_started', [
                '%actor%' => new TranslatableMessage('board.card.history.actor.agent', ['%name%' => 'Riley Chen']),
                '%kind%' => 'implement',
            ]), $entry->sentence, $state);
        }
    }

    public function test_a_run_written_with_a_rule_name_reads_it_as_the_work_kind(): void
    {
        $detail = $this->runDetail('succeeded', 1);
        unset($detail['workKind']);
        $detail['ruleName'] = 'plan';

        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, $detail), true);

        self::assertSame('plan', $entry->sentence->getParameters()['%kind%']);
        self::assertSame('board.card.history.run_finished', $entry->sentence->getMessage());
    }

    public function test_a_run_with_no_work_kind_names_none(): void
    {
        $finished = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, ['state' => 'succeeded']));
        $notStarted = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, ['state' => 'not-started']));

        self::assertSame('board.card.history.run_finished_no_kind', $finished->sentence->getMessage());
        self::assertSame('board.card.history.run_not_started_no_kind', $notStarted->sentence->getMessage());
        self::assertNotNull($finished->run);
    }

    public function test_a_purged_run_keeps_its_row_with_no_link(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, $this->runDetail('failed', null)));

        self::assertNotNull($entry->run);
        self::assertNull($entry->run->runId);
        self::assertNull($entry->run->duration);
        self::assertSame('failed', $entry->run->chipModifier);
    }

    public function test_an_unknown_run_state_shows_a_neutral_chip(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, Actor::Agent, $this->user, $this->runDetail('evaporated', 5)), true);

        self::assertNotNull($entry->run);
        self::assertSame('board.card.history.run_state_unknown', $entry->run->stateKey);
        self::assertSame('resolved', $entry->run->chipModifier);
    }

    public function test_the_run_id_is_read_only_when_it_is_a_uuid(): void
    {
        self::assertSame(self::RUN_ID, CardHistoryEntry::runIdOf($this->event(CardEventKind::RunFinished, Actor::Agent, null, $this->runDetail('succeeded', 1))));
        self::assertNull(CardHistoryEntry::runIdOf($this->event(CardEventKind::RunFinished, Actor::Agent, null, ['runId' => 'not-a-uuid'])));
        self::assertNull(CardHistoryEntry::runIdOf($this->event(CardEventKind::Created, Actor::Human, null, ['column' => self::BACKLOG])));
    }

    /** @return iterable<string, array{string, string}> */
    public static function pauseKinds(): iterable
    {
        yield 'a rule' => ['rule', 'board.card.history.pause_kind.rule'];
        yield 'retries' => ['retries', 'board.card.history.pause_kind.retries'];
        yield 'a work limit' => ['work-limit', 'board.card.history.pause_kind.work_limit'];
        yield 'a stopped worker' => ['work-stopped', 'board.card.history.pause_kind.work_stopped'];
        yield 'a work timeout written before the timeout was removed' => ['work-timeout', 'board.card.history.pause_kind.work_timeout'];
    }

    #[DataProvider('pauseKinds')]
    public function test_a_pause_names_its_kind_and_its_rule(string $kind, string $kindKey): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Paused, Actor::System, null, ['kind' => $kind, 'reason' => 'review-failed', 'ruleId' => 'fix-on-review']));

        self::assertSame('lucide:circle-pause', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.paused', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%kind%' => new TranslatableMessage($kindKey),
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'fix-on-review']), $entry->cause);
        self::assertNull($entry->run);
    }

    public function test_a_release_names_the_person_and_the_kind_of_the_pause(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::PauseReleased, Actor::Human, $this->user, ['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review']));

        self::assertSame('lucide:circle-play', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.pause_released', [
            '%actor%' => 'Riley Chen',
            '%kind%' => new TranslatableMessage('board.card.history.pause_kind.retries'),
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'fix-on-review']), $entry->cause);
    }

    #[DataProvider('releaseReasons')]
    public function test_a_release_names_why_the_pause_ended_and_the_rule(string $releaseReason, string $reasonKey): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::PauseReleased, Actor::System, null, ['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review', 'releaseReason' => $releaseReason]));

        self::assertEquals(new TranslatableMessage('board.card.history.pause_released', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%kind%' => new TranslatableMessage('board.card.history.pause_kind.retries'),
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.pause_released', [
            '%reason%' => new TranslatableMessage($reasonKey),
            '%rule%' => 'fix-on-review',
        ]), $entry->cause);
    }

    /** @return iterable<string, array{string, string}> */
    public static function releaseReasons(): iterable
    {
        yield 'changed facts' => ['facts-changed', 'board.card.history.release_reason.facts_changed'];
        yield 'another pull request' => ['subject-changed', 'board.card.history.release_reason.subject_changed'];
        yield 'a refilled budget' => ['refilled', 'board.card.history.release_reason.refilled'];
        yield 'a met condition' => ['until-met', 'board.card.history.release_reason.until_met'];
        yield 'an inbox turned on' => ['inbox-on', 'board.card.history.release_reason.inbox_on'];
        yield 'a card out of the column' => ['left-slot', 'board.card.history.release_reason.left_slot'];
        yield 'a pull request that closed' => ['pull-request-closed', 'board.card.history.release_reason.pull_request_closed'];
        yield 'a removed rule' => ['rule-removed', 'board.card.history.release_reason.rule_removed'];
        yield 'a resumed run' => ['run-resumed', 'board.card.history.release_reason.run_resumed'];
        yield 'a release rule' => ['released-by-rule', 'board.card.history.release_reason.released_by_rule'];
        yield 'a person' => ['released-by-person', 'board.card.history.release_reason.released_by_person'];
        yield 'a removed work timeout' => ['work-timeout-removed', 'board.card.history.release_reason.work_timeout_removed'];
    }

    public function test_a_release_with_an_unknown_reason_keeps_the_rule_cause(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::PauseReleased, Actor::System, null, ['kind' => 'retries', 'ruleId' => 'fix-on-review', 'releaseReason' => 'gremlins']));

        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'fix-on-review']), $entry->cause);
    }

    public function test_a_pause_ignores_a_release_reason(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Paused, Actor::System, null, ['kind' => 'retries', 'ruleId' => 'fix-on-review', 'releaseReason' => 'facts-changed']));

        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'fix-on-review']), $entry->cause);
    }

    public function test_a_verdict_names_the_person_and_the_decision(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Verdict, Actor::Human, $this->user, ['kind' => 'request-changes', 'noteCount' => 2]));

        self::assertSame('lucide:message-square', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.verdict', [
            '%actor%' => 'Riley Chen',
            '%verdict%' => new TranslatableMessage('board.card.history.verdict_kind.request_changes'),
        ]), $entry->sentence);
        self::assertNull($entry->cause);
        self::assertNull($entry->run);
    }

    public function test_an_override_names_the_approver_the_pull_request_and_the_head(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ReviewOverridden, Actor::System, null, ['pullRequest' => 7, 'approver' => 'ubermuda', 'headSha' => 'abcdef1234567890']));

        self::assertEquals(new TranslatableMessage('board.card.history.review_overridden', ['%login%' => 'ubermuda', '%pr%' => 7, '%sha%' => 'abcdef1']), $entry->sentence);
    }

    public function test_the_end_of_an_override_names_the_pull_request_and_the_head(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ReviewOverrideEnded, Actor::System, null, ['pullRequest' => 7, 'headSha' => 'abcdef1234567890']));

        self::assertEquals(new TranslatableMessage('board.card.history.review_override_ended', ['%pr%' => 7, '%sha%' => 'abcdef1']), $entry->sentence);
    }

    public function test_an_automatic_approval_names_the_pull_request_and_the_head(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::AutoApproved, Actor::System, null, ['pullRequest' => 7, 'headSha' => 'abcdef1234567890', 'rule' => 'pull-request-auto-approve']));

        self::assertSame('lucide:shield-check', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.auto_approved', ['%pr%' => 7, '%sha%' => 'abcdef1']), $entry->sentence);
    }

    public function test_a_dismissed_approval_names_the_pull_request(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ApprovalDismissed, Actor::System, null, ['pullRequest' => 7, 'rule' => 'dismiss-app-approval']));

        self::assertSame('lucide:shield-off', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.approval_dismissed', ['%pr%' => 7]), $entry->sentence);
    }

    public function test_an_approval_row_with_no_pull_request_reads_as_changed(): void
    {
        foreach ([CardEventKind::AutoApproved, CardEventKind::ApprovalDismissed] as $kind) {
            self::assertSame('board.card.history.changed', CardHistoryEntry::of($this->event($kind, Actor::System, null, []))->sentence->getMessage());
        }
    }

    public function test_a_skipped_resume_names_the_question(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ResumeSkipped, Actor::System, null, ['number' => 963, 'sessionId' => 'x']));

        self::assertSame('lucide:circle-slash', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.resume_skipped', ['%number%' => 963]), $entry->sentence);
    }

    public function test_a_skipped_resume_with_no_question_number_reads_as_changed(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ResumeSkipped, Actor::System, null, []));

        self::assertSame('board.card.history.changed', $entry->sentence->getMessage());
    }

    /** @return iterable<string, array{CardEventKind, array<string, mixed>}> */
    public static function incompleteOverrideRows(): iterable
    {
        yield 'an override with no approver' => [CardEventKind::ReviewOverridden, ['pullRequest' => 7, 'headSha' => 'abcdef1']];
        yield 'an override with no head' => [CardEventKind::ReviewOverridden, ['pullRequest' => 7, 'approver' => 'ubermuda']];
        yield 'an end with no head' => [CardEventKind::ReviewOverrideEnded, ['pullRequest' => 7]];
    }

    /** @param array<string, mixed> $detail */
    #[DataProvider('incompleteOverrideRows')]
    public function test_an_incomplete_override_row_reads_as_changed(CardEventKind $kind, array $detail): void
    {
        $entry = CardHistoryEntry::of($this->event($kind, Actor::System, null, $detail));

        self::assertSame('board.card.history.changed', $entry->sentence->getMessage());
    }

    public function test_a_pause_with_no_rule_has_no_cause_line(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Paused, Actor::System, null, ['kind' => 'rule']));

        self::assertSame('board.card.history.paused', $entry->sentence->getMessage());
        self::assertNull($entry->cause);
    }

    public function test_an_approval_by_a_rule_names_the_rule_and_the_document(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::DocumentVerdict, Actor::System, null, ['rule' => 'tech-design-auto-approve', 'document' => 'Tech design', 'verdict' => 'approved']));

        self::assertSame('lucide:circle-check', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.document_approved_by_rule', ['%rule%' => 'tech-design-auto-approve', '%document%' => 'Tech design']), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.auto_approval'), $entry->cause);
    }

    public function test_a_change_request_by_a_rule_names_the_rule_and_the_document(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::DocumentVerdict, Actor::System, null, ['rule' => 'product-design-changes', 'document' => 'Product design', 'verdict' => 'changes-requested']));

        self::assertSame('lucide:circle-alert', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.changes_requested_by_rule', ['%rule%' => 'product-design-changes', '%document%' => 'Product design']), $entry->sentence);
        self::assertNull($entry->cause);
    }

    #[DataProvider('withdrawals')]
    public function test_a_withdrawal_names_the_person_and_what_they_took_back(string $withdrawn, string $key): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::DocumentVerdict, Actor::Human, $this->user, ['rule' => null, 'document' => 'Tech design', 'verdict' => 'withdrawn', 'withdrawn' => $withdrawn]));

        self::assertSame('lucide:undo-2', $entry->icon);
        self::assertEquals(new TranslatableMessage($key, ['%actor%' => 'Riley Chen', '%document%' => 'Tech design']), $entry->sentence);
        self::assertNull($entry->cause);
    }

    /** @return iterable<string, array{string, string}> */
    public static function withdrawals(): iterable
    {
        yield 'an approval' => ['approved', 'board.card.history.approval_withdrawn'];
        yield 'a change request' => ['changes-requested', 'board.card.history.change_request_withdrawn'];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_approval_names_the_refusal(string $refusal, string $causeKey): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::VerdictFailed, Actor::System, null, ['rule' => 'tech-design-auto-approve', 'document' => 'Tech design', 'refusal' => $refusal]));

        self::assertSame('lucide:circle-x', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.verdict_failed', ['%rule%' => 'tech-design-auto-approve', '%document%' => 'Tech design']), $entry->sentence);
        self::assertEquals(new TranslatableMessage($causeKey), $entry->cause);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'a newer version' => ['verdict_stale', 'board.card.history.verdict_failed_cause.verdict_stale'];
        yield 'a verdict that landed since' => ['verdict_changed', 'board.card.history.verdict_failed_cause.verdict_changed'];
        yield 'a decision with no answer' => ['decisions_unanswered', 'board.card.history.verdict_failed_cause.decisions_unanswered'];
        yield 'a refusal this version does not know' => ['gremlins', 'board.card.history.verdict_failed_cause.other'];
    }

    /** @param array<string, mixed> $detail */
    #[DataProvider('malformedDetails')]
    public function test_a_row_with_unreadable_detail_falls_back_to_a_plain_sentence(CardEventKind $kind, array $detail): void
    {
        $entry = CardHistoryEntry::of($this->event($kind, Actor::Human, $this->user, $detail));

        self::assertSame('lucide:history', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.changed', ['%actor%' => 'Riley Chen']), $entry->sentence);
        self::assertNull($entry->cause);
        self::assertNull($entry->run);
    }

    /** @return iterable<string, array{CardEventKind, array<string, mixed>}> */
    public static function malformedDetails(): iterable
    {
        yield 'a creation with no column' => [CardEventKind::Created, []];
        yield 'a move with no target' => [CardEventKind::Moved, ['from' => self::BACKLOG]];
        yield 'a move whose column has no label' => [CardEventKind::Moved, ['from' => self::BACKLOG, 'to' => ['id' => 'x']]];
        yield 'a fix request with no pull request' => [CardEventKind::FixRequested, ['reason' => 'conflict']];
        yield 'a stop with no pull request' => [CardEventKind::Stopped, []];
        yield 'a ready pull request with a text number' => [CardEventKind::ReadyToMerge, ['pullRequest' => 'twelve']];
        yield 'a pause with no kind' => [CardEventKind::Paused, ['ruleId' => 'fix-on-review']];
        yield 'a verdict with an unknown kind' => [CardEventKind::Verdict, ['kind' => 'gremlins']];
        yield 'a document verdict with no document' => [CardEventKind::DocumentVerdict, ['rule' => 'r', 'verdict' => 'approved']];
        yield 'a document verdict by a person' => [CardEventKind::DocumentVerdict, ['rule' => null, 'document' => 'D', 'verdict' => 'approved']];
        yield 'a document verdict of an unknown kind' => [CardEventKind::DocumentVerdict, ['rule' => 'r', 'document' => 'D', 'verdict' => 'gremlins']];
        yield 'a withdrawal of an unknown verdict' => [CardEventKind::DocumentVerdict, ['rule' => null, 'document' => 'D', 'verdict' => 'withdrawn', 'withdrawn' => 'gremlins']];
        yield 'a failed verdict with no document' => [CardEventKind::VerdictFailed, ['rule' => 'r', 'refusal' => 'verdict_stale']];
        yield 'a release with an unknown kind' => [CardEventKind::PauseReleased, ['kind' => 'gremlins', 'ruleId' => 'fix-on-review']];
    }

    /** @return iterable<string, array{Actor, bool, string|TranslatableMessage}> */
    public static function actors(): iterable
    {
        yield 'a person' => [Actor::Human, true, 'Riley Chen'];
        yield 'an agent' => [Actor::Agent, true, new TranslatableMessage('board.card.history.actor.agent', ['%name%' => 'Riley Chen'])];
        yield 'the app' => [Actor::System, false, new TranslatableMessage('board.card.history.actor.system')];
        yield 'a reviewer' => [Actor::Reviewer, false, new TranslatableMessage('board.card.history.actor.reviewer')];
        yield 'a deleted person' => [Actor::Human, false, new TranslatableMessage('board.card.history.actor.deleted')];
        yield 'an agent of a deleted person' => [Actor::Agent, false, new TranslatableMessage('board.card.history.actor.deleted')];
    }

    #[DataProvider('actors')]
    public function test_the_actor_label_follows_the_actor_kind(Actor $kind, bool $withUser, string|TranslatableMessage $expected): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Created, $kind, $withUser ? $this->user : null, ['column' => self::BACKLOG]));

        self::assertEquals($expected, $entry->actor);
        self::assertEquals($expected, $entry->sentence->getParameters()['%actor%']);
    }

    public function test_a_value_turned_on_names_the_declared_subject(): void
    {
        $entry = CardHistoryEntry::of(
            $this->event(CardEventKind::ValueChanged, Actor::Human, $this->user, ['key' => 'tech-design-interactive', 'value' => true, 'previous' => null]),
            historyPhrases: ['tech-design-interactive' => 'workflow.value.tech_design_interactive.history'],
        );

        self::assertSame('lucide:settings', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.value_on', [
            '%actor%' => 'Riley Chen',
            '%subject%' => new TranslatableMessage('workflow.value.tech_design_interactive.history'),
        ]), $entry->sentence);
    }

    public function test_a_value_turned_off_uses_the_off_sentence(): void
    {
        $entry = CardHistoryEntry::of(
            $this->event(CardEventKind::ValueChanged, Actor::Human, $this->user, ['key' => 'tech-design-interactive', 'value' => false, 'previous' => true]),
            historyPhrases: ['tech-design-interactive' => 'workflow.value.tech_design_interactive.history'],
        );

        self::assertSame('board.card.history.value_off', $entry->sentence->getMessage());
    }

    public function test_a_value_of_a_key_the_template_no_longer_declares_falls_back_to_the_raw_key(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ValueChanged, Actor::Human, $this->user, ['key' => 'gone-key', 'value' => true, 'previous' => null]));

        self::assertSame('board.card.history.value_on', $entry->sentence->getMessage());
        self::assertSame('gone-key', $entry->sentence->getParameters()['%subject%']);
    }

    /** @param array<string, mixed> $detail */
    #[DataProvider('unreadableValueDetails')]
    public function test_a_value_change_with_an_unreadable_detail_gives_the_changed_sentence(array $detail): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ValueChanged, Actor::Human, $this->user, $detail));

        self::assertSame('board.card.history.changed', $entry->sentence->getMessage());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadableValueDetails(): iterable
    {
        yield 'no key' => [['value' => true]];
        yield 'a value that is no boolean' => [['key' => 'a-key', 'value' => 'yes']];
        yield 'no value' => [['key' => 'a-key']];
    }

    /** @return array<string, mixed> */
    private function runDetail(string $state, ?int $durationSeconds): array
    {
        return [
            'runId' => self::RUN_ID,
            'workKind' => 'implement',
            'state' => $state,
            'interactive' => false,
            'command' => false,
            'startedAt' => '2026-09-30T10:00:00+00:00',
            'endedAt' => '2026-09-30T10:03:12+00:00',
            'durationSeconds' => $durationSeconds,
        ];
    }

    /** @param array<string, mixed> $detail */
    private function event(CardEventKind $kind, Actor $actorKind, ?User $actor, array $detail): CardEvent
    {
        $project = new Project($this->user, 'history');
        $column = new BoardColumn($project, 'board.column.backlog', 'backlog', 0);
        $card = new Card(project: $project, column: $column, title: 'A card', body: '', number: 1);

        return new CardEvent($card, $project, $kind, $actorKind, $actor, $detail, new \DateTimeImmutable('2026-09-30 10:00'));
    }
}
