<?php

declare(strict_types=1);

namespace App\Module\Project\Event;

/**
 * A card of the project entered or left a terminal column, so the count of finished cards changed. Dispatched inside the
 * move, with the project id only.
 */
final readonly class ProjectCardsFinishedChanged
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
