<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** One dated event of a card, named as a duration metric names it. An arrival or a departure also names the slot. */
final readonly class CardMilestone
{
    public function __construct(
        public string $event,
        public \DateTimeImmutable $at,
        public ?string $slot = null,
    ) {
    }
}
