<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Only the user an OpenRouter topic names may listen on it, and only a person
 * who may manage the project may listen on a project topic. No voter covers a
 * user acting on their own account, so the ids are compared here.
 */
final readonly class OpenRouterTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private UserTopicBuilder $userTopics,
        private ProjectTopicBuilder $projectTopics,
        private ProjectRepository $projects,
        private Security $security,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $userId = $this->userTopics->userIdFromOpenRouterTopic($topic);
        if (null !== $userId) {
            $user = $this->security->getUser();

            return $user instanceof User && null !== $user->id && $user->id->equals($userId);
        }

        $projectId = $this->projectTopics->projectIdFromOpenRouterTopic($topic);
        if (null === $projectId) {
            return null;
        }

        $project = $this->projects->find($projectId);

        return null !== $project && $this->authorization->isGranted(ProjectVoter::MANAGE, $project);
    }
}
