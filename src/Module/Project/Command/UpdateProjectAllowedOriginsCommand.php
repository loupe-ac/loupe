<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateProjectAllowedOriginsCommand
{
    /**
     * @param string|array<mixed> $origins One origin per line, as the owner typed them, or a list with one origin per
     *                                     entry. The handler refuses an entry that is no string or holds a line break.
     */
    public function __construct(
        public Project $project,
        public string|array $origins,
    ) {
    }
}
