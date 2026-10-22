<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PersonWork;
use App\Module\Workflow\Contract\WithdrawKind;
use App\Module\Workflow\Contract\WorkLedger;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cancels the open work requests of the card and asks the bridge to stop each open background run of it, then writes one
 * `stopped` history row for each run it asked to stop. An interactive session stays: the move that follows closes its row.
 * A request that a person started stays too, because no rule owns it. A run that is stopping already, or has a stop pending,
 * is skipped, so a retry of the rule writes no second row.
 */
final readonly class StopWork implements Action
{
    public const string KEY = 'stop-work';

    private const int MAX_RUNS = 100;

    public function __construct(
        private CardRepository $cards,
        private WorkLedger $ledger,
        private WorkerRunRepository $workerRuns,
        private RequestBridgeCommandHandler $requestCommand,
        private CardEventRepository $cardEvents,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
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
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true, listable: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.stop_work', 'workflow.panel.action.stop_work');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        foreach ($this->ledger->live($cardId) as $request) {
            if (!PersonWork::owns($request->ruleId)) {
                $this->ledger->withdraw($request->id, WithdrawKind::Cancelled);
            }
        }

        $reason = $this->translator->trans('workflow.stop_work.reason', ['%rule%' => $context->ruleId]);
        $now = $this->clock->now();
        foreach ($this->workerRuns->findOpenForCard($card->project, $cardId, self::MAX_RUNS) as $run) {
            if (WorkerRunKind::Interactive === $run->kind) {
                continue;
            }
            try {
                ($this->requestCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::StopRun, null, $reason));
            } catch (DomainErrors) {
                continue;
            }
            $this->cardEvents->record($card, CardEventKind::Stopped, Actor::System, null, [
                'runId' => (string) $run->id,
                'workKind' => $run->workKind,
                'rule' => $context->ruleId,
                'durationSeconds' => null === $run->startedAt ? null : max(0, $now->getTimestamp() - $run->startedAt->getTimestamp()),
            ], $now);
        }

        return ActionOutcome::done();
    }
}
