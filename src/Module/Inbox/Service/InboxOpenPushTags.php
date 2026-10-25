<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Notification\Contract\OpenPushTags;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** The push tags of the open asks and notices in the projects a user owns. */
#[AsAlias(OpenPushTags::class)]
final readonly class InboxOpenPushTags implements OpenPushTags
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
    ) {
    }

    #[\Override]
    public function forUser(User $user): array
    {
        $tags = [];
        foreach ($this->inboxAsks->findOpenByOwner($user) as $ask) {
            $tags[] = self::askTag($ask->id ?? throw new \LogicException('A stored ask has an id.'));
        }
        foreach ($this->inboxItems->findOpenNoticesByOwner($user) as $notice) {
            $tags[] = self::itemTag($notice->id ?? throw new \LogicException('A stored item has an id.'));
        }

        return $tags;
    }

    public static function askTag(Uuid $askId): string
    {
        return 'ask-'.$askId->toRfc4122();
    }

    public static function itemTag(Uuid $itemId): string
    {
        return 'item-'.$itemId->toRfc4122();
    }
}
