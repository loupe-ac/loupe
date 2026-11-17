<?php

declare(strict_types=1);

namespace App\Module\Project\Sidebar;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The sidebar counters of every module, read as one. */
final readonly class SidebarCounters
{
    /** @param iterable<SidebarCounterInterface> $counters */
    public function __construct(
        #[AutowireIterator('app.sidebar_counter')]
        private iterable $counters,
    ) {
    }

    /** @return array{agents: string, inbox: string} an empty string for a version that a page cannot render */
    public function renderVersions(Project $project, User $viewer): array
    {
        $versions = [];
        foreach ($this->counters as $counter) {
            $versions = [...$versions, ...$counter->renderVersions($project, $viewer)];
        }

        return $this->complete($versions);
    }

    /** @return array<string, string> only the keys that a module with a pill reports */
    public function storedVersions(Project $project, User $viewer): array
    {
        $versions = [];
        foreach ($this->counters as $counter) {
            $versions = [...$versions, ...$counter->storedVersions($project, $viewer)];
        }

        return $versions;
    }

    /** @return list<string> */
    public function streams(Project $project, User $viewer): array
    {
        $streams = [];
        foreach ($this->counters as $counter) {
            $streams = [...$streams, ...$counter->streams($project, $viewer)];
        }

        return $streams;
    }

    /**
     * @param array<string, string> $versions
     *
     * @return array{agents: string, inbox: string}
     */
    private function complete(array $versions): array
    {
        return [
            'agents' => $versions[SidebarCounterInterface::VERSION_AGENTS] ?? '',
            'inbox' => $versions[SidebarCounterInterface::VERSION_INBOX] ?? '',
        ];
    }
}
