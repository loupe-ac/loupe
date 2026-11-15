<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Project\Entity\Project;

/**
 * A hash of the settings that the project edit forms show. A form renders it at
 * load, and a live region compares it with the current one after a reconnect,
 * to learn whether a change slipped by while the page was offline.
 */
final readonly class ProjectSettingsFingerprint
{
    public function of(Project $project): string
    {
        return hash('sha256', json_encode([
            $project->name,
            $project->slug,
            $project->description,
            $project->domain,
            $project->searchLanguage->value,
            $project->allowedOrigins,
            $project->readinessGuideHiddenAt?->format(\DATE_ATOM),
            $project->agentGitHubLogin,
        ], \JSON_THROW_ON_ERROR));
    }
}
