<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;

/**
 * Tells the open pages of a user that their push devices changed. The message
 * holds no state: a page that reads the warning itself cannot go back in time
 * when two messages arrive out of order.
 */
final readonly class DeviceChangedPublisher
{
    public const string TYPE = 'notifications.devices_changed';

    public function __construct(
        private UserTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /** Call it after the change commits. The topic is built now, because the user may be detached later. */
    public function changed(User $user): void
    {
        $userId = $user->id ?? throw new \LogicException('User has no id.');
        $this->publisher->queue($this->topics->forNotifications($userId), ['type' => self::TYPE]);
    }
}
