<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\Uid\Uuid;

/**
 * Tells the open account pages of one user that an area of the account
 * changed. The message names the area and nothing else, so a page that reads
 * the state itself cannot go back in time when two messages arrive out of
 * order.
 */
final readonly class AccountChangedPublisher
{
    public const string TYPE = 'account.changed';

    public function __construct(
        private UserTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /** Call it after the change commits. The topic is built now, because the user may be detached later. */
    public function changed(Uuid $userId, string $area): void
    {
        $this->publisher->queue($this->topics->forAccount($userId), [
            'type' => self::TYPE,
            'area' => $area,
        ]);
    }
}
