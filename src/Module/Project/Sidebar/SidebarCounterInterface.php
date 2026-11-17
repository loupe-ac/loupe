<?php

declare(strict_types=1);

namespace App\Module\Project\Sidebar;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A module's pills in the project sidebar, with the versions that tell a page whether they changed. */
#[AutoconfigureTag('app.sidebar_counter')]
interface SidebarCounterInterface
{
    public const string VERSION_AGENTS = 'agents';

    public const string VERSION_INBOX = 'inbox';

    /**
     * The versions for a page render. It creates a missing one, so call it before any count is read.
     * A version that cannot be read or created is an empty string. A module that has no pill shows
     * no key.
     *
     * @return array<string, string>
     */
    public function renderVersions(Project $project, User $viewer): array;

    /**
     * The versions as stored, with none created. A version that is missing or cannot be read is an
     * empty string.
     *
     * @return array<string, string>
     */
    public function storedVersions(Project $project, User $viewer): array;

    /** @return list<string> one rendered turbo-stream element for each pill */
    public function streams(Project $project, User $viewer): array;
}
