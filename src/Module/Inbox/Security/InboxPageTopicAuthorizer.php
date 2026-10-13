<?php

declare(strict_types=1);

namespace App\Module\Inbox\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Whoever may view a project may listen for changes of its inbox, while the inbox is on. */
final readonly class InboxPageTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private ProjectRepository $projects,
        private InboxAvailability $inbox,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $projectId = $this->topics->projectIdFromInboxTopic($topic);
        if (null === $projectId) {
            return null;
        }

        $project = $this->projects->find($projectId);

        return null !== $project && $this->inbox->isEnabled() && $this->authorization->isGranted(ProjectVoter::VIEW, $project);
    }
}
