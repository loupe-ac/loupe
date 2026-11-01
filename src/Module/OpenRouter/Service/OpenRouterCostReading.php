<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

/** What one analytics read of a session answered. The cost is set for a total alone. */
final readonly class OpenRouterCostReading
{
    private function __construct(
        public OpenRouterCostOutcome $outcome,
        public ?string $costUsd = null,
    ) {
    }

    /** The cost has six decimals, so two reads compare as strings. */
    public static function total(string $costUsd): self
    {
        return new self(OpenRouterCostOutcome::Total, $costUsd);
    }

    public static function noCost(): self
    {
        return new self(OpenRouterCostOutcome::NoCost);
    }

    public static function refused(): self
    {
        return new self(OpenRouterCostOutcome::Refused);
    }

    public static function noAnswer(): self
    {
        return new self(OpenRouterCostOutcome::NoAnswer);
    }
}
