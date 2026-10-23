<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Repository\ProjectRepository;
use App\Utils\Slug;

/** Reads the slug of the project that makes a name unavailable to its owner. */
final readonly class FindTakenProjectSlugHandler
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(FindTakenProjectSlugCommand $command): ?string
    {
        $project = $this->projects->findOneByOwnerAndName($command->owner, $command->name)
            ?? $this->projects->findOneByOwnerAndSlug($command->owner, Slug::fromName($command->name));

        return $project?->slug;
    }
}
