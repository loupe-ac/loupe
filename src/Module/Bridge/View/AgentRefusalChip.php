<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\RefusedRequest;
use App\Module\Bridge\ValueObject\RefusedTaker;

/** The chip on a bridge card while the server refuses its claim on an open request. A version gap reads before a named account. */
final readonly class AgentRefusalChip
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public string $label,
        public string $cause,
        public array $parameters,
        public string $tone,
    ) {
    }

    /** @param list<RefusedRequest> $refusals the open requests of the project that the bridge was refused */
    public static function for(Bridge $bridge, array $refusals): ?self
    {
        if ([] === $refusals) {
            return null;
        }

        $label = 'bridge.agents.refused.chip';
        if (array_any($refusals, static fn (RefusedRequest $refusal): bool => RefusedTaker::hasVersionGap($refusal->account, $bridge->cliVersion))) {
            return new self($label, 'bridge.agents.refused.cause.version_gap', [], 'failed');
        }

        $named = array_find($refusals, static fn (RefusedRequest $refusal): bool => null !== $refusal->account)?->account;

        return null === $named
            ? new self($label, 'bridge.agents.refused.cause.no_account', [], 'failed')
            : new self($label, 'bridge.agents.refused.cause.account', ['%account%' => $named], 'failed');
    }
}
