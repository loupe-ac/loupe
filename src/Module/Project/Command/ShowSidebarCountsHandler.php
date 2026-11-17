<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Sidebar\SidebarCounterInterface;
use App\Module\Project\Sidebar\SidebarCounters;

/**
 * The pills of the project sidebar. The count reads wait until the page's
 * versions differ from the stored ones, so a quiet project costs none.
 */
final readonly class ShowSidebarCountsHandler
{
    public function __construct(
        private SidebarCounters $counters,
    ) {
    }

    public function __invoke(ShowSidebarCountsCommand $command): SidebarCountsView
    {
        $rendered = explode('.', $command->since ?? '', 2);
        $rendered = [
            SidebarCounterInterface::VERSION_AGENTS => $rendered[0],
            SidebarCounterInterface::VERSION_INBOX => $rendered[1] ?? '',
        ];

        if ($this->matches($rendered, $this->counters->storedVersions($command->project, $command->viewer))) {
            return new SidebarCountsView(true, []);
        }

        return new SidebarCountsView(false, $this->counters->streams($command->project, $command->viewer));
    }

    /**
     * @param array<string, string> $rendered
     * @param array<string, string> $stored
     */
    private function matches(array $rendered, array $stored): bool
    {
        if (!\array_key_exists(SidebarCounterInterface::VERSION_AGENTS, $stored)) {
            return false;
        }

        return array_all($stored, fn ($version, $key) => !('' === $version || $version !== ($rendered[$key] ?? '')));
    }
}
