<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\View\InboxActionVerb;
use App\Module\Inbox\View\InboxReturnTarget;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells the owner what a saved response did.
 *
 * The inbox page reads its own flash type, because it shows the line above the
 * ask. The global flash list ignores that type. The card tab and the document
 * page keep the plain success flash they always showed.
 */
final readonly class InboxActionFeedback
{
    public const string STATUS_FLASH = 'inbox_status';

    public function __construct(
        private RequestStack $requests,
        private TranslatorInterface $translator,
    ) {
    }

    public function saved(InboxReturnTarget $saved, InboxItem $item, InboxActionVerb $verb): void
    {
        $session = $this->requests->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        if (!$saved->isInbox()) {
            $session->getFlashBag()->add('success', $this->translator->trans($verb->flashKey(), ['%number%' => $item->number]));

            return;
        }

        $session->getFlashBag()->add(self::STATUS_FLASH, $this->status($saved->acted, $item, $verb));
    }

    /**
     * What the line says: the ask that closed, how many items of the ask are
     * left, or the item alone when no ask holds it.
     *
     * @return array<string, int|string|null>
     */
    private function status(?InboxAsk $ask, InboxItem $item, InboxActionVerb $verb): array
    {
        $status = ['verb' => $verb->value, 'number' => $item->number, 'ask' => null, 'title' => null, 'left' => 0];
        if (null === $ask) {
            return $status;
        }

        if (null !== $ask->closedAt) {
            $first = $ask->items->first();

            return [...$status, 'ask' => (string) $ask->id, 'title' => false === $first ? $item->title : $first->item->title];
        }

        $open = $ask->items->filter(static fn (InboxAskItem $link): bool => InboxItemState::Open === $link->item->state)->count();

        return [...$status, 'left' => $open];
    }
}
