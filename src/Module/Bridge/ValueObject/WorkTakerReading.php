<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The answer of the work takers check for one open work request. */
final readonly class WorkTakerReading
{
    /**
     * @param \DateTimeImmutable  $noTakerSince the later of the start of the wait and the moment the last able bridge went quiet
     * @param ?\DateTimeImmutable $quietAt      the moment the answer next changes because an online bridge turns quiet, or null when it cannot
     */
    public function __construct(
        public WorkTakerState $state,
        public \DateTimeImmutable $noTakerSince,
        public ?\DateTimeImmutable $quietAt,
    ) {
    }
}
