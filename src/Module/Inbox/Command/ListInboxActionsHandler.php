<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\InboxLinkResolver;
use App\Module\Inbox\Service\ItemActions;

/** Reads which built-in actions fit a card now, from the open wait item of the card. */
final readonly class ListInboxActionsHandler
{
    public function __construct(
        private InboxLinkResolver $links,
        private InboxCardWatchRepository $inboxCardWatches,
        private ItemActions $itemActions,
    ) {
    }

    /** @return list<InboxActionKind>|null the kinds that fit the card, or null when the call names no card */
    public function __invoke(ListInboxActionsCommand $command): ?array
    {
        if (null === $command->cardId) {
            return null;
        }

        $card = $this->links->cards($command->project, [$command->cardId->toRfc4122()], 'cardId')[0]
            ?? throw new \LogicException('The resolver returns one card for one id.');
        $watch = $this->inboxCardWatches->findOpenForCards($command->project, [$card->id ?? throw new \LogicException('A stored card has an id.')])[0] ?? null;

        return $this->itemActions->forCard($watch?->item);
    }
}
