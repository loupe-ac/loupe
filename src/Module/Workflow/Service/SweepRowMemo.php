<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\SweepProjectRows;
use App\Observability\HandledMessage;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Keeps project rows in the memory of the sweep worker for a short time. It is no ResetInterface, because the
 * worker resets its services after each message and the rows must outlive that. A message of any other transport
 * reads every time, so a request or an event evaluation never sees a kept row.
 */
#[AsAlias(SweepProjectRows::class)]
final class SweepRowMemo implements SweepProjectRows
{
    public const int LIFETIME_SECONDS = 60;

    /** @var array<string, array{at: \DateTimeImmutable, value: mixed}> */
    private array $kept = [];

    public function __construct(
        private readonly HandledMessage $handled,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function remember(string $key, \Closure $read): mixed
    {
        if (EvaluationTrigger::LOW_PRIORITY_TRANSPORT !== $this->handled->current?->transport) {
            return $read();
        }

        $now = $this->clock->now();
        $entry = $this->kept[$key] ?? null;
        if (null !== $entry && $entry['at']->modify('+'.self::LIFETIME_SECONDS.' seconds') > $now) {
            return $entry['value'];
        }

        $value = $read();
        $this->kept[$key] = ['at' => $now, 'value' => $value];

        return $value;
    }
}
