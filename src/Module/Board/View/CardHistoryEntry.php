<?php

declare(strict_types=1);

namespace App\Module\Board\View;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * One row of the History tab, read from a card event.
 *
 * The detail is JSON that older writers shaped differently, so a field that
 * does not read gives the plain `changed` sentence rather than an error.
 */
final readonly class CardHistoryEntry
{
    private const array REASONS = [
        'conflict' => 'board.card.history.reason.conflict',
        'checks-failed' => 'board.card.history.reason.checks_failed',
        'changes-requested' => 'board.card.history.reason.changes_requested',
        'agent-review' => 'board.card.history.reason.agent_review',
    ];

    private const array REFUSALS = [
        'verdict_stale' => 'board.card.history.verdict_failed_cause.verdict_stale',
        'verdict_changed' => 'board.card.history.verdict_failed_cause.verdict_changed',
        'decisions_unanswered' => 'board.card.history.verdict_failed_cause.decisions_unanswered',
    ];

    private const array RELEASE_REASONS = [
        'facts-changed' => 'board.card.history.release_reason.facts_changed',
        'subject-changed' => 'board.card.history.release_reason.subject_changed',
        'refilled' => 'board.card.history.release_reason.refilled',
        'until-met' => 'board.card.history.release_reason.until_met',
        'inbox-on' => 'board.card.history.release_reason.inbox_on',
        'left-slot' => 'board.card.history.release_reason.left_slot',
        'pull-request-closed' => 'board.card.history.release_reason.pull_request_closed',
        'rule-removed' => 'board.card.history.release_reason.rule_removed',
        'run-resumed' => 'board.card.history.release_reason.run_resumed',
        'released-by-rule' => 'board.card.history.release_reason.released_by_rule',
        'released-by-person' => 'board.card.history.release_reason.released_by_person',
    ];

    private function __construct(
        public string $icon,
        public \DateTimeImmutable $occurredAt,
        public string|TranslatableMessage $actor,
        public TranslatableMessage $sentence,
        public ?TranslatableMessage $cause = null,
        public ?CardHistoryRun $run = null,
    ) {
    }

    /**
     * @param bool                  $runExists      whether the run of a `run-finished` row is still stored, so the row can link to it
     * @param array<string, string> $historyPhrases the history translation key of each value key the template declares
     */
    public static function of(CardEvent $event, bool $runExists = false, array $historyPhrases = []): self
    {
        $actor = self::actor($event);
        $detail = $event->detail;

        $entry = match ($event->kind) {
            CardEventKind::Created => self::created($event, $actor, $detail),
            CardEventKind::Moved => self::moved($event, $actor, $detail),
            CardEventKind::FixRequested => self::automation($event, $actor, $detail, 'lucide:wrench', 'board.card.history.fix_requested'),
            CardEventKind::Stopped => \is_string($detail['runId'] ?? null)
                ? self::stoppedRun($event, $actor, $detail, $runExists)
                : self::automation($event, $actor, $detail, 'lucide:ban', 'board.card.history.stopped'),
            CardEventKind::ReadyToMerge => \is_int($detail['pullRequest'] ?? null)
                ? new self('lucide:git-merge', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.ready_to_merge', ['%pr%' => $detail['pullRequest']]))
                : null,
            CardEventKind::Synced => \is_int($detail['pullRequest'] ?? null)
                ? new self('lucide:git-compare', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.synced', ['%actor%' => $actor, '%pr%' => $detail['pullRequest']]))
                : null,
            CardEventKind::RunFinished => self::runFinished($event, $actor, $detail, $runExists),
            CardEventKind::Paused => self::pause($event, $actor, $detail, 'lucide:circle-pause', 'board.card.history.paused'),
            CardEventKind::PauseReleased => self::pause($event, $actor, $detail, 'lucide:circle-play', 'board.card.history.pause_released'),
            CardEventKind::Verdict => self::verdict($event, $actor, $detail),
            CardEventKind::ReviewOverridden => \is_int($detail['pullRequest'] ?? null) && \is_string($detail['approver'] ?? null) && \is_string($detail['headSha'] ?? null)
                ? new self('lucide:shield-check', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.review_overridden', ['%login%' => $detail['approver'], '%pr%' => $detail['pullRequest'], '%sha%' => mb_substr($detail['headSha'], 0, 7)]))
                : null,
            CardEventKind::ReviewOverrideEnded => \is_int($detail['pullRequest'] ?? null) && \is_string($detail['headSha'] ?? null)
                ? new self('lucide:shield-off', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.review_override_ended', ['%pr%' => $detail['pullRequest'], '%sha%' => mb_substr($detail['headSha'], 0, 7)]))
                : null,
            CardEventKind::DocumentVerdict => self::documentVerdict($event, $actor, $detail),
            CardEventKind::VerdictFailed => self::verdictFailed($event, $actor, $detail),
            CardEventKind::AutoApproved => \is_int($detail['pullRequest'] ?? null) && \is_string($detail['headSha'] ?? null)
                ? new self('lucide:shield-check', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.auto_approved', ['%pr%' => $detail['pullRequest'], '%sha%' => mb_substr($detail['headSha'], 0, 7)]))
                : null,
            CardEventKind::ApprovalDismissed => \is_int($detail['pullRequest'] ?? null)
                ? new self('lucide:shield-off', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.approval_dismissed', ['%pr%' => $detail['pullRequest']]))
                : null,
            CardEventKind::HumanReviewChanged => self::humanReview($event, $actor, $detail),
            CardEventKind::ValueChanged => self::valueChanged($event, $actor, $detail, $historyPhrases),
            CardEventKind::ResumeSkipped => \is_int($detail['number'] ?? null)
                ? new self('lucide:circle-slash', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.resume_skipped', ['%number%' => $detail['number']]))
                : null,
        };

        return $entry ?? new self('lucide:history', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.changed', ['%actor%' => $actor]));
    }

    /** The run id of a `run-finished` row or of a `stopped` row of a run, when it is a UUID a query can bind. */
    public static function runIdOf(CardEvent $event): ?string
    {
        $runId = $event->detail['runId'] ?? null;

        return \in_array($event->kind, [CardEventKind::RunFinished, CardEventKind::Stopped], true) && \is_string($runId) && Uuid::isValid($runId) ? $runId : null;
    }

    private static function actor(CardEvent $event): string|TranslatableMessage
    {
        return self::actorLabel($event->actorKind, $event->actorUser);
    }

    /** How the app names who made a change: a person by name, an agent by its owner, the app and a reviewer by role. */
    public static function actorLabel(Actor $kind, ?User $user): string|TranslatableMessage
    {
        return match ($kind) {
            Actor::System => new TranslatableMessage('board.card.history.actor.system'),
            Actor::Reviewer => new TranslatableMessage('board.card.history.actor.reviewer'),
            Actor::Human => $user->fullName ?? new TranslatableMessage('board.card.history.actor.deleted'),
            Actor::Agent => null === $user
                ? new TranslatableMessage('board.card.history.actor.deleted')
                : new TranslatableMessage('board.card.history.actor.agent', ['%name%' => $user->fullName]),
        };
    }

    /** @param array<string, mixed> $detail */
    private static function created(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $column = self::column($detail['column'] ?? null);

        return null === $column ? null : new self('lucide:plus', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.created', [
            '%actor%' => $actor,
            '%column%' => $column,
        ]));
    }

    /** @param array<string, mixed> $detail */
    private static function moved(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $from = self::column($detail['from'] ?? null);
        $to = self::column($detail['to'] ?? null);
        if (null === $from || null === $to) {
            return null;
        }

        return new self('lucide:arrow-right', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.moved', [
            '%actor%' => $actor,
            '%from%' => $from,
            '%to%' => $to,
        ]), self::cause($detail['cause'] ?? null));
    }

    /** @param array<string, mixed> $detail */
    private static function automation(CardEvent $event, string|TranslatableMessage $actor, array $detail, string $icon, string $key): ?self
    {
        $pullRequest = $detail['pullRequest'] ?? null;
        if (!\is_int($pullRequest)) {
            return null;
        }
        $reason = $detail['reason'] ?? null;
        $reasonKey = \is_string($reason) ? self::REASONS[$reason] ?? null : null;

        return new self(
            $icon,
            $event->occurredAt,
            $actor,
            new TranslatableMessage($key, ['%actor%' => $actor, '%pr%' => $pullRequest]),
            null === $reasonKey ? null : new TranslatableMessage('board.card.history.reason', ['%reason%' => new TranslatableMessage($reasonKey)]),
        );
    }

    /** @param array<string, mixed> $detail */
    private static function pause(CardEvent $event, string|TranslatableMessage $actor, array $detail, string $icon, string $key): ?self
    {
        $kindKey = \is_string($detail['kind'] ?? null) ? self::pauseKindKey($detail['kind']) : null;
        if (null === $kindKey) {
            return null;
        }
        $ruleId = $detail['ruleId'] ?? null;
        $rule = \is_string($ruleId) && '' !== $ruleId ? $ruleId : null;
        $releaseReason = CardEventKind::PauseReleased === $event->kind && \is_string($detail['releaseReason'] ?? null)
            ? self::RELEASE_REASONS[$detail['releaseReason']] ?? null
            : null;

        return new self(
            $icon,
            $event->occurredAt,
            $actor,
            new TranslatableMessage($key, ['%actor%' => $actor, '%kind%' => new TranslatableMessage($kindKey)]),
            match (true) {
                null === $rule => null,
                null === $releaseReason => new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => $rule]),
                default => new TranslatableMessage('board.card.history.cause.pause_released', ['%reason%' => new TranslatableMessage($releaseReason), '%rule%' => $rule]),
            },
        );
    }

    /** An event from before the work timeout was removed still names the `work-timeout` kind. */
    private static function pauseKindKey(string $kind): ?string
    {
        if ('work-timeout' === $kind) {
            return 'board.card.history.pause_kind.work_timeout';
        }

        return match (PauseKind::tryFrom($kind)) {
            PauseKind::Rule => 'board.card.history.pause_kind.rule',
            PauseKind::Retries => 'board.card.history.pause_kind.retries',
            PauseKind::WorkLimit => 'board.card.history.pause_kind.work_limit',
            PauseKind::WorkStopped => 'board.card.history.pause_kind.work_stopped',
            null => null,
        };
    }

    /** @param array<string, mixed> $detail */
    private static function verdict(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $kind = \is_string($detail['kind'] ?? null) ? CardVerdictKind::tryFrom($detail['kind']) : null;
        if (null === $kind) {
            return null;
        }

        return new self('lucide:message-square', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.verdict', [
            '%actor%' => $actor,
            '%verdict%' => new TranslatableMessage(match ($kind) {
                CardVerdictKind::Approve => 'board.card.history.verdict_kind.approve',
                CardVerdictKind::RequestChanges => 'board.card.history.verdict_kind.request_changes',
                CardVerdictKind::Comment => 'board.card.history.verdict_kind.comment',
            }),
        ]));
    }

    /** @param array<string, mixed> $detail */
    private static function documentVerdict(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $document = $detail['document'] ?? null;
        $rule = $detail['rule'] ?? null;
        if (!\is_string($document) || '' === $document) {
            return null;
        }
        if ('withdrawn' === ($detail['verdict'] ?? null)) {
            $key = match ($detail['withdrawn'] ?? null) {
                'approved' => 'board.card.history.approval_withdrawn',
                'changes-requested' => 'board.card.history.change_request_withdrawn',
                default => null,
            };

            return null === $key ? null : new self('lucide:undo-2', $event->occurredAt, $actor, new TranslatableMessage($key, ['%actor%' => $actor, '%document%' => $document]));
        }
        if (!\is_string($rule) || '' === $rule) {
            return null;
        }

        return match ($detail['verdict'] ?? null) {
            'approved' => new self(
                'lucide:circle-check',
                $event->occurredAt,
                $actor,
                new TranslatableMessage('board.card.history.document_approved_by_rule', ['%rule%' => $rule, '%document%' => $document]),
                new TranslatableMessage('board.card.history.cause.auto_approval'),
            ),
            'changes-requested' => new self(
                'lucide:circle-alert',
                $event->occurredAt,
                $actor,
                new TranslatableMessage('board.card.history.changes_requested_by_rule', ['%rule%' => $rule, '%document%' => $document]),
            ),
            default => null,
        };
    }

    /** @param array<string, mixed> $detail */
    private static function humanReview(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $value = $detail['value'] ?? null;
        if (!\is_bool($value)) {
            return null;
        }
        $ruleId = $detail['ruleId'] ?? null;

        return new self(
            'lucide:user-check',
            $event->occurredAt,
            $actor,
            new TranslatableMessage($value ? 'board.card.history.human_review_on' : 'board.card.history.human_review_off', ['%actor%' => $actor]),
            match ($detail['cause'] ?? null) {
                'rule' => \is_string($ruleId) && '' !== $ruleId ? new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => $ruleId]) : null,
                'rollout' => new TranslatableMessage('board.card.history.cause.human_review_rollout'),
                default => null,
            },
        );
    }

    /**
     * @param array<string, mixed>  $detail
     * @param array<string, string> $historyPhrases
     */
    private static function valueChanged(CardEvent $event, string|TranslatableMessage $actor, array $detail, array $historyPhrases): ?self
    {
        $key = $detail['key'] ?? null;
        $value = $detail['value'] ?? null;
        if (!\is_string($key) || '' === $key || !\is_bool($value)) {
            return null;
        }
        $phrase = $historyPhrases[$key] ?? null;

        return new self(
            'lucide:settings',
            $event->occurredAt,
            $actor,
            new TranslatableMessage($value ? 'board.card.history.value_on' : 'board.card.history.value_off', [
                '%actor%' => $actor,
                '%subject%' => null === $phrase ? $key : new TranslatableMessage($phrase),
            ]),
        );
    }

    /** @param array<string, mixed> $detail */
    private static function verdictFailed(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $document = $detail['document'] ?? null;
        $rule = $detail['rule'] ?? null;
        if (!\is_string($document) || '' === $document || !\is_string($rule) || '' === $rule) {
            return null;
        }
        $refusal = \is_string($detail['refusal'] ?? null) ? $detail['refusal'] : '';

        return new self(
            'lucide:circle-x',
            $event->occurredAt,
            $actor,
            new TranslatableMessage('board.card.history.verdict_failed', ['%rule%' => $rule, '%document%' => $document]),
            new TranslatableMessage(self::REFUSALS[$refusal] ?? 'board.card.history.verdict_failed_cause.other'),
        );
    }

    /** @param array<string, mixed> $detail */
    private static function stoppedRun(CardEvent $event, string|TranslatableMessage $actor, array $detail, bool $runExists): self
    {
        $stored = $detail['workKind'] ?? null;
        $kind = \is_string($stored) && '' !== $stored ? $stored : null;
        $rule = $detail['rule'] ?? null;
        $seconds = $detail['durationSeconds'] ?? null;

        return new self(
            'lucide:circle-stop',
            $event->occurredAt,
            $actor,
            new TranslatableMessage(null === $kind ? 'board.card.history.stopped_work_no_kind' : 'board.card.history.stopped_work', ['%actor%' => $actor, '%kind%' => $kind ?? '']),
            \is_string($rule) && '' !== $rule ? new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => $rule]) : null,
            new CardHistoryRun(
                runId: $runExists ? self::runIdOf($event) : null,
                duration: \is_int($seconds) && $seconds >= 0 ? WorkerRunListItem::formatDuration($seconds) : null,
                stateKey: WorkerRunState::Stopped->translationKey(),
                chipModifier: 'neutral',
                interactive: false,
                command: false,
            ),
        );
    }

    /** @param array<string, mixed> $detail */
    private static function runFinished(CardEvent $event, string|TranslatableMessage $actor, array $detail, bool $runExists): self
    {
        // A row written before runs carried a work kind holds the rule name.
        $stored = $detail['workKind'] ?? $detail['ruleName'] ?? null;
        $kind = \is_string($stored) && '' !== $stored ? $stored : null;
        $rawState = $detail['state'] ?? null;
        $state = \is_string($rawState) ? WorkerRunState::tryFrom($rawState) : null;
        $seconds = $detail['durationSeconds'] ?? null;

        return new self(
            'lucide:bot',
            $event->occurredAt,
            $actor,
            new TranslatableMessage(
                (\in_array($state, [WorkerRunState::NotStarted, WorkerRunState::Skipped, WorkerRunState::Replaced, WorkerRunState::Dropped], true)
                    ? 'board.card.history.run_not_started'
                    : 'board.card.history.run_finished').(null === $kind ? '_no_kind' : ''),
                ['%actor%' => $actor, '%kind%' => $kind ?? ''],
            ),
            run: new CardHistoryRun(
                runId: $runExists ? self::runIdOf($event) : null,
                duration: \is_int($seconds) && $seconds >= 0 ? WorkerRunListItem::formatDuration($seconds) : null,
                stateKey: $state?->translationKey() ?? 'board.card.history.run_state_unknown',
                chipModifier: $state?->chipModifier() ?? 'resolved',
                interactive: true === ($detail['interactive'] ?? null),
                command: true === ($detail['command'] ?? null),
            ),
        );
    }

    /** A seeded column stores its label as a translation key, and a label a person wrote translates to itself. */
    private static function column(mixed $column): ?TranslatableMessage
    {
        $label = \is_array($column) ? $column['label'] ?? null : null;

        return \is_string($label) ? new TranslatableMessage($label) : null;
    }

    private static function cause(mixed $cause): ?TranslatableMessage
    {
        if (!\is_array($cause)) {
            return null;
        }
        // A removed link or a column change names no single blocker.
        if ('unblocked' === ($cause['type'] ?? null) && !\array_key_exists('blocker', $cause)) {
            return new TranslatableMessage('board.card.history.cause.unblocked_any');
        }
        // A cause written before runs carried a work kind holds the rule name.
        if ('run' === ($cause['type'] ?? null)) {
            $kind = $cause['kind'] ?? $cause['rule'] ?? null;

            return \is_string($kind) && '' !== $kind
                ? new TranslatableMessage('board.card.history.cause.run', ['%kind%' => $kind])
                : new TranslatableMessage('board.card.history.cause.run_no_kind');
        }
        [$key, $field, $parameter] = match ($cause['type'] ?? null) {
            'checks-passed' => ['board.card.history.cause.checks_passed', 'pullRequest', '%pr%'],
            'document-approved' => ['board.card.history.cause.document_approved', 'document', '%document%'],
            'epic-reconciled' => ['board.card.history.cause.epic_reconciled', 'child', '%child%'],
            'unblocked' => ['board.card.history.cause.unblocked', 'blocker', '%blocker%'],
            'column-deleted' => ['board.card.history.cause.column_deleted', 'column', '%column%'],
            'abandoned' => ['board.card.history.cause.abandoned', null, null],
            'workflow-rule' => ['board.card.history.cause.workflow_rule', 'rule', '%rule%'],
            default => [null, null, null],
        };
        if (null === $key) {
            return null;
        }
        if (null === $field || null === $parameter) {
            return new TranslatableMessage($key);
        }
        $value = $cause[$field] ?? null;
        if (!\is_int($value) && !\is_string($value)) {
            return null;
        }

        return new TranslatableMessage($key, [$parameter => 'column' === $field && \is_string($value) ? new TranslatableMessage($value) : $value]);
    }
}
