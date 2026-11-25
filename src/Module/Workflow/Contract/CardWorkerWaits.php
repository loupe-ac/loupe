<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Lets the engine read and end the open wait of a worker on a card. The module that stores the waits implements it. */
interface CardWorkerWaits
{
    public function openFor(Uuid $cardId): ?OpenWait;

    /**
     * Ends the wait, and asks for the resume of its session when the run of the wait has ended already.
     *
     * @param list<WaitedCondition> $met the conditions that were true, empty for a time limit or a gone pull request
     */
    public function end(Uuid $waitId, WaitEnd $reason, array $met): void;
}
