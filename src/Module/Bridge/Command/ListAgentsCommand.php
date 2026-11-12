<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ListAgentsCommand
{
    public function __construct(
        public Project $project,
        /** Set with a limit to walk the bridges by id: the id of the last bridge of the previous page. */
        public ?Uuid $after = null,
        /** Set to read a page of bridges ordered by id, and to learn whether more follow. */
        public ?int $limit = null,
    ) {
    }
}
