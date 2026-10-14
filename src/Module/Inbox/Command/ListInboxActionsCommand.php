<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ListInboxActionsCommand
{
    public function __construct(
        public Project $project,
        public ?Uuid $cardId = null,
    ) {
    }
}
