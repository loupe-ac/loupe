<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\View\AccountInboxDetailView;
use App\Module\Inbox\View\AccountInboxRow;
use App\Module\Project\Entity\Project;

final readonly class ShowAccountInboxHandler
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(ShowAccountInboxCommand $command): AccountInboxDetailView
    {
        $projects = [];
        $counts = [];
        $oldestWaits = [];
        $blocking = [];
        $wait = static function (string $key, \DateTimeImmutable $createdAt) use (&$oldestWaits): void {
            if (!isset($oldestWaits[$key]) || $createdAt < $oldestWaits[$key]) {
                $oldestWaits[$key] = $createdAt;
            }
        };

        foreach ($this->inboxAsks->findOpenByOwner($command->owner) as $ask) {
            $key = (string) $ask->project->id;
            $projects[$key] = $ask->project;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $wait($key, $ask->createdAt);
            foreach ($ask->items as $link) {
                if (InboxItemState::Open === $link->item->state && $link->item->blocking) {
                    $blocking[$key] = true;
                }
            }
        }

        foreach ($this->inboxItems->findOpenOutsideOpenAsksByOwner($command->owner) as $item) {
            $key = (string) $item->project->id;
            $projects[$key] = $item->project;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $wait($key, $item->createdAt);
            if ($item->blocking) {
                $blocking[$key] = true;
            }
        }

        uasort($projects, static fn (Project $a, Project $b): int => [mb_strtolower($a->name), (string) $a->id] <=> [mb_strtolower($b->name), (string) $b->id]);

        $rows = [];
        foreach ($projects as $key => $project) {
            $rows[] = new AccountInboxRow($project, $counts[$key] ?? 0, $oldestWaits[$key] ?? null, $blocking[$key] ?? false);
        }

        return new AccountInboxDetailView($rows);
    }
}
