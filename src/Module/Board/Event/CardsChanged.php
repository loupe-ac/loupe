<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use Symfony\Component\Uid\Uuid;

/**
 * These cards of one project changed, each with a `CardChanged` event marked as
 * batched that came before this one. A listener that does one job for the
 * whole list answers this event, and skips the batched single events.
 */
final readonly class CardsChanged
{
    /** @param list<Uuid> $cardIds */
    public function __construct(
        public Uuid $projectId,
        public array $cardIds,
    ) {
    }
}
