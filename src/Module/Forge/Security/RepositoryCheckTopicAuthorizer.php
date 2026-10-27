<?php

declare(strict_types=1);

namespace App\Module\Forge\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Module\Forge\Service\RepositoryCheckTopics;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Only the owner of a project may listen for changes of its repository checks. */
final readonly class RepositoryCheckTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private RepositoryCheckTopics $topics,
        private ProjectRepository $projects,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $projectId = $this->topics->projectIdFromTopic($topic);
        if (null === $projectId) {
            return null;
        }

        $project = $this->projects->find($projectId);

        return null !== $project && $this->authorization->isGranted(ProjectVoter::MANAGE, $project);
    }
}
