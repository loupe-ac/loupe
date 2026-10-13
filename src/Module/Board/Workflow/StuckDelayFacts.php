<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ParameterValue;

/** The stuck delay of the project, in minutes. A delay condition reads it when its rule names no delay. */
final readonly class StuckDelayFacts
{
    public function __construct(
        public int $minutes,
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function minutesFor(Facts $facts, array $params): int
    {
        return ParameterValue::optionalInt($params, 'delay') ?? $facts->get(self::class)->minutes;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<class-string>
     */
    public static function readsFor(array $params): array
    {
        return null === ParameterValue::optionalInt($params, 'delay') ? [self::class] : [];
    }
}
