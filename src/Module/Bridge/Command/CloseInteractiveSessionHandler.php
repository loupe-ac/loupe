<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;

final readonly class CloseInteractiveSessionHandler
{
    public function __construct(
        private InteractiveRuns $interactiveRuns,
    ) {
    }

    /** The number of runs it closed, which is 0 when the owner holds no open run of the session. */
    public function __invoke(CloseInteractiveSessionCommand $command): int
    {
        return $this->interactiveRuns->closeSession($command->owner, $command->sessionId, WorkerRunCloseReason::SessionEnded);
    }
}
