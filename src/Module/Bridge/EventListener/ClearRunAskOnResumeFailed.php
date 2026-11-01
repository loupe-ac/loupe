<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Command\ClearRunAskCommand;
use App\Module\Bridge\Command\ClearRunAskHandler;
use App\Module\Bridge\Event\BridgeCommandSettled;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A resume after an answer that the bridge refused, or that expired or was cancelled, leaves the run stopped, so its question no longer stands for the stop. */
#[AsEventListener]
final readonly class ClearRunAskOnResumeFailed
{
    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private ClearRunAskHandler $clearRunAsk,
    ) {
    }

    public function __invoke(BridgeCommandSettled $event): void
    {
        $command = $this->bridgeCommands->find($event->commandId);
        if (null === $command
            || BridgeCommandKind::ResumeRun !== $command->kind
            || BridgeCommandCause::AskClosed !== $command->cause
            || !\in_array($command->state, [BridgeCommandState::Refused, BridgeCommandState::Expired, BridgeCommandState::Cancelled], true)) {
            return;
        }

        $run = $command->workerRun;
        if (null === $run) {
            return;
        }

        ($this->clearRunAsk)(new ClearRunAskCommand($run));
    }
}
