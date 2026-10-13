<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\View\InboxView;
use App\Module\Project\Entity\Project;

final readonly class ShowInboxCommand
{
    public function __construct(
        public Project $project,
        public InboxView $view = new InboxView(),
    ) {
    }
}
