<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAction;

/** Tells the open inbox pages that the status of some actions changed. One message for each project. */
final readonly class InboxActionRefresher
{
    public function __construct(
        private InboxChangedPublisher $publisher,
    ) {
    }

    /** @param list<InboxAction> $actions */
    public function refresh(array $actions): void
    {
        $projects = [];
        foreach ($actions as $action) {
            $projects[(string) $action->item->project->id] = $action->item->project;
        }
        foreach ($projects as $project) {
            $this->publisher->pageChanged($project);
        }
    }
}
