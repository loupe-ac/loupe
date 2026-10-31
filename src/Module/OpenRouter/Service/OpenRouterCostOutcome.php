<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

enum OpenRouterCostOutcome
{
    /** OpenRouter answered with the cost of at least one request. */
    case Total;

    /** OpenRouter answered and found no request for the session. */
    case NoCost;

    /** A 401 or 403: OpenRouter does not accept the key. */
    case Refused;

    /** A transport fault, a limit, a server fault or an answer that cannot be read. */
    case NoAnswer;

    /** The key was accepted, whatever the session held. */
    public function keyWorked(): bool
    {
        return self::Total === $this || self::NoCost === $this;
    }
}
