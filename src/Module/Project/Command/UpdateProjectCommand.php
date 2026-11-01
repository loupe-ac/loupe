<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Doctrine\SearchLanguage;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;

/**
 * Raw input, checked by the handler. A null value keeps what the project holds.
 * An empty domain or description clears it.
 */
final readonly class UpdateProjectCommand
{
    public function __construct(
        public Project $project,
        public ?string $name,
        public ?string $domain,
        public SearchLanguage|string|null $searchLanguage,
        /** @phpstan-var ProjectEventType::ACTOR_* */
        public string $actor,
        public ?string $description = null,
    ) {
    }
}
