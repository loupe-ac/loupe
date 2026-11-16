<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Mercure\CountChangeGate;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ShowInboxOpenTotalCommand;
use App\Module\Inbox\Command\ShowInboxOpenTotalHandler;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Sidebar\SidebarCounterInterface;
use Twig\Environment;

/** The inbox pill of the project and the open total of the viewer, which the sidebar shows with the inbox flag on. */
final readonly class InboxSidebarCounter implements SidebarCounterInterface
{
    public function __construct(
        private InboxAvailability $inbox,
        private CountChangeGate $gate,
        private InboxItemRepository $inboxItems,
        private ShowInboxOpenTotalHandler $openTotal,
        private Environment $twig,
    ) {
    }

    #[\Override]
    public function renderVersions(Project $project, User $viewer): array
    {
        if (!$this->inbox->isEnabled()) {
            return [];
        }

        return [self::VERSION_INBOX => $this->gate->currentVersion(InboxCountsSignal::ownerVersionKey($viewer->id ?? throw new \LogicException('User has no id.'))) ?? ''];
    }

    #[\Override]
    public function storedVersions(Project $project, User $viewer): array
    {
        if (!$this->inbox->isEnabled()) {
            return [];
        }

        return [self::VERSION_INBOX => $this->gate->storedVersion(InboxCountsSignal::ownerVersionKey($viewer->id ?? throw new \LogicException('User has no id.'))) ?? ''];
    }

    #[\Override]
    public function streams(Project $project, User $viewer): array
    {
        if (!$this->inbox->isEnabled()) {
            return [];
        }

        return [$this->twig->render('@Inbox/_sidebar_counts.stream.html.twig', [
            'project' => $project,
            'openCount' => $this->inboxItems->countOpenRowsByProject($project),
            'total' => ($this->openTotal)(new ShowInboxOpenTotalCommand($viewer)),
        ])];
    }
}
