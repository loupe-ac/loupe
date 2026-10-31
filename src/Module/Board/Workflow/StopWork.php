<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Entity\WorkerRun;
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
 * Asks the bridge to stop each open background run of the card, then cancels the open work requests of the card. It writes
 * one `stopped` history row for each run it asked to stop. The runs go in bridge order, and the bridge locks come before
 * the lock of any work request, the order of a claim, so a pass and a claim cannot wait for each other.
 * An interactive session stays: the move that follows closes its row. A request that a person started stays too,
 * because no rule owns it, and so does an open background run that such a request started. A run that is stopping already, or has a stop pending, is skipped, so a retry of the rule
 * writes no second row. Any other refused stop, or more open runs than one pass reads, refuses the action, so the
 * move that follows waits for a retry.
 */
final readonly class StopWork implements Action
{
    public const string KEY = 'stop-work';

    public const string STOP_REFUSED = 'stop-run-refused';

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

        $reason = $this->translator->trans('workflow.stop_work.reason', ['%rule%' => $context->ruleId]);
        $now = $this->clock->now();
        $open = $this->workerRuns->findOpenForCard($card->project, $cardId, self::MAX_RUNS + 1, WorkerRunKind::Interactive);
        $refused = \count($open) > self::MAX_RUNS;
        $runs = array_values(array_filter(\array_slice($open, 0, self::MAX_RUNS), fn (WorkerRun $run): bool => !$this->startedByPerson($run)));
        usort($runs, static fn (WorkerRun $a, WorkerRun $b): int => strcmp((string) $a->bridgeId, (string) $b->bridgeId));
        foreach ($runs as $run) {
            try {
                ($this->requestCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::StopRun, null, $reason));
            } catch (DomainErrors $e) {
                $refusal = $e->errors['run'] ?? null;
                if (RequestBridgeCommandHandler::PENDING !== $refusal && RequestBridgeCommandHandler::NOT_STOPPABLE !== $refusal) {
                    $refused = true;
                }
                continue;
            }
            $this->cardEvents->record($card, CardEventKind::Stopped, Actor::System, null, [
                'runId' => (string) $run->id,
                'workKind' => $run->workKind,
                'rule' => $context->ruleId,
                'durationSeconds' => null === $run->startedAt ? null : max(0, $now->getTimestamp() - $run->startedAt->getTimestamp()),
            ], $now);
        }

        foreach ($this->ledger->live($cardId) as $request) {
            if (!PersonWork::owns($request->ruleId)) {
                $this->ledger->withdraw($request->id, WithdrawKind::Cancelled);
            }
        }

        return $refused ? ActionOutcome::refused(self::STOP_REFUSED) : ActionOutcome::done();
    }

    /** The run reads its rule id from its work request, and from its own copy when the request is gone. A run with neither is a rule's. */
    private function startedByPerson(WorkerRun $run): bool
    {
        $request = null === $run->workRequestId ? null : $this->ledger->find($run->workRequestId);
        $ruleId = $request->ruleId ?? $run->ruleId;

        return null !== $ruleId && PersonWork::owns($ruleId);
    }
}
