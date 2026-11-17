<?php

declare(strict_types=1);

namespace App\Module\Project\Twig;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Sidebar\SidebarCounters;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SidebarCountVersionsExtension extends AbstractExtension
{
    public function __construct(
        private readonly SidebarCounters $counters,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('sidebar_count_versions', $this->versions(...))];
    }

    /**
     * The versions of the sidebar pills, for the first check of a page. Read them before any count.
     * A version is an empty string when it cannot be read or created, or has no pill.
     *
     * @return array{agents: string, inbox: string}
     */
    public function versions(Project $project): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->counters->renderVersions($project, $user) : ['agents' => '', 'inbox' => ''];
    }
}
