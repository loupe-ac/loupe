<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionOutcomeKind;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterNames;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\RetriesWhileTrue;
use App\Module\Workflow\Contract\WorkHolds;

/** A fix request also writes the fix-requested card event, which feeds the card history only. */
final readonly class RequestWork implements Action, RetriesWhileTrue
{
    public const string KEY = 'request';

    public function __construct(
        private CardRepository $cards,
        private WorkRequestOpener $opener,
        private CardPullRequests $cardPullRequests,
        private CardEventRepository $cardEvents,
        private WorkHolds $workHolds,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('kind', ParameterType::String),
            new Parameter('role', ParameterType::String, required: false, choices: ['code-review', 'design-review', 'design-revise', 'fix']),
            new Parameter('capability', ParameterType::String, required: false),
            new Parameter('reason', ParameterType::String, required: false),
            new Parameter('limit', ParameterType::Int, required: false, min: 1),
            new Parameter('refill', ParameterType::Expression, required: false, needs: 'limit'),
            new Parameter('cancelWhen', ParameterType::Expression, required: false),
            new Parameter('cancelOnly', ParameterType::String, required: false, choices: ['open'], needs: 'cancelWhen'),
            new Parameter('onNoTaker', ParameterType::String, required: false, choices: ['fail']),
            new Parameter('stillTrue', ParameterType::String, required: false, choices: ['retry']),
            new Parameter('document', ParameterType::Document, required: false),
            new Parameter('checks', ParameterType::List, required: false),
            new Parameter(ParameterNames::PROMPT, ParameterType::String, required: false, pattern: ParameterNames::PROMPT_PATTERN, patternHint: '[a-z][a-z0-9-], at most 40 characters'),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(countsTowardLimit: true, refreshesFacts: true);
    }

    #[\Override]
    public static function retriesWhileTrue(array $params): bool
    {
        return 'retry' === ($params['stillTrue'] ?? null);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.request', 'workflow.panel.action.request', panelParams: ['%kind%' => (string) $params['kind']], settingsDetail: (string) $params['kind']);
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return isset($params['kind']) ? (string) $params['kind'] : null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        if (null !== $this->workHolds->heldProject($context->card->projectId)) {
            return ActionOutcome::held();
        }

        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $limit = $context->optionalInt('limit');
        if (null !== $limit && $context->fires >= $limit) {
            return ActionOutcome::pause(PauseKind::WorkLimit, 'work-limit-reached');
        }

        $kind = $context->string('kind');
        $reason = $context->optionalString('reason');
        $role = $context->optionalString('role');
        $outcome = $this->opener->open($context, $kind, $context->optionalString('capability'), $reason, $role);
        if ('fix' === $role && ActionOutcomeKind::Done === $outcome->kind && !$outcome->alreadyLive) {
            $this->recordFixRequested($card, $context->facts, $reason);
        }

        return $outcome;
    }

    private function recordFixRequested(Card $card, Facts $facts, ?string $reason): void
    {
        $detail = ['reason' => $reason ?? $facts->pullRequest?->fixReason() ?? 'unknown'];
        $number = $this->cardPullRequests->subjectOf($this->cardPullRequests->forCard($card), $facts->pullRequest)?->number;
        if (null !== $number) {
            $detail['pullRequest'] = $number;
        }
        $this->cardEvents->record($card, CardEventKind::FixRequested, Actor::System, null, $detail, $facts->now);
    }
}
