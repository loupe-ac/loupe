<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The projects whose setup page the owner skipped in one browser session. Only a person who may manage the
 * project can skip it, and the list counts only for such a person.
 */
final readonly class ProjectSetupSkips
{
    public const string SESSION_KEY = 'project_setup_skipped';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function isSkipped(SessionInterface $session, Project $project): bool
    {
        if (!$this->authorizationChecker->isGranted(ProjectVoter::MANAGE, $project)) {
            return false;
        }

        return \in_array((string) $project->id, $this->skippedIds($session), true);
    }

    /** Whether the project pages send this person to the setup page now. */
    public function gateApplies(SessionInterface $session, Project $project): bool
    {
        return !$project->setUp && !$this->isSkipped($session, $project);
    }

    public function skip(SessionInterface $session, Project $project): void
    {
        $skipped = $this->skippedIds($session);
        $id = (string) $project->id;
        if (!\in_array($id, $skipped, true)) {
            $skipped[] = $id;
        }
        $session->set(self::SESSION_KEY, $skipped);
    }

    /** @return list<string> */
    private function skippedIds(SessionInterface $session): array
    {
        $skipped = $session->get(self::SESSION_KEY, []);

        return \is_array($skipped) ? array_values(array_filter($skipped, \is_string(...))) : [];
    }
}
