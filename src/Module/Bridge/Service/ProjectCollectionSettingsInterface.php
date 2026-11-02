<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

/** Bridge may not import Insights, so Insights answers this from the project settings. */
interface ProjectCollectionSettingsInterface
{
    public function collectFullText(Project $project): bool;

    /**
     * @return list<string>|null the programs the project sets, or null when the instance list applies
     */
    public function subcommandPrograms(Project $project): ?array;

    /**
     * The settings of every project the owner holds, in one read.
     *
     * @return array<string, array{collectFullText: bool, subcommandPrograms: ?list<string>}> keyed by project id; a project with no settings has no key
     */
    public function forOwner(User $owner): array;
}
