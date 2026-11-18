<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Workflow\Contract\CardEventCause;

/** The worker run behind an agent's MCP call, read from the run credential of the call. */
final readonly class AgentRunCause
{
    public function __construct(
        private CallerRun $callerRun,
    ) {
    }

    /** Null when the call holds no run credential, or its run is not reported in the project of the card. */
    public function forCard(Card $card): ?CardEventCause
    {
        $run = $this->callerRun->workerRun($card->project);
        if (null === $run) {
            return null;
        }

        return CardEventCause::run($run->id ?? throw new \LogicException('A persisted run has an id.'), $run->workKind);
    }
}
