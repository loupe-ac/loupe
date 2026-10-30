<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Asks the engine to evaluate cards again after their facts changed. */
interface CardEvaluations
{
    /** The transport for a message that one action of a person starts and that the person waits on. */
    public const string INTERACTIVE_TRANSPORT = 'interactive';

    /** @param list<string|Uuid> $cardIds */
    public function forCards(array $cardIds): void;

    /**
     * Like forCards(), for the cards a person waits on. Each evaluation rides the interactive transport, and
     * a PersonWaitingCardEvaluated event follows it.
     *
     * @param list<string|Uuid> $cardIds
     */
    public function forPersonWaiting(array $cardIds): void;

    /** True while the engine can run on this instance. A listener checks it before its own repository read. */
    public function isOn(): bool;
}
