<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The opener of a rule's work asks this port whether a person retried that rule with a note, so the worker can read it. */
interface RetryNotes
{
    /** The retry whose release re-armed this rule of the card, when no request has carried it yet. */
    public function forRuleWork(Uuid $cardId, string $ruleId): ?RetryNote;
}
