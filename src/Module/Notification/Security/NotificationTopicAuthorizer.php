<?php

declare(strict_types=1);

namespace App\Module\Notification\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Only the user a notifications topic names may listen on it. No voter covers a
 * user acting on their own account, so the ids are compared here. No flag
 * gates it, because the device list exists with the inbox off.
 */
final readonly class NotificationTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private UserTopicBuilder $topics,
        private Security $security,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $userId = $this->topics->userIdFromNotificationsTopic($topic);
        if (null === $userId) {
            return null;
        }

        $user = $this->security->getUser();

        return $user instanceof User
            && null !== $user->id
            && $user->id->equals($userId);
    }
}
