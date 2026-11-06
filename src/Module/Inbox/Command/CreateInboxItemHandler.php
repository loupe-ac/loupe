<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxCardTileRefresher;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Inbox\Service\InboxItemDrafter;
use App\Module\Inbox\Service\InboxSearchIndexer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Hands one item from the public API to the owner. Nothing waits on it, so its
 * ask has no session and closes at once.
 */
final readonly class CreateInboxItemHandler
{
    public const array KINDS = [InboxItemKind::Question, InboxItemKind::Todo, InboxItemKind::Notice];

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxItemDrafter $drafter,
        private InboxSearchIndexer $searchIndexer,
        private InboxChangedPublisher $inboxChanged,
        private InboxCardTileRefresher $cardTiles,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CreateInboxItemCommand $command): CreateInboxItemView
    {
        if (!\in_array($command->item->kind, self::KINDS, true) || true === $command->item->blocking) {
            throw new \LogicException('The request refuses another kind and a blocking item.');
        }
        // Before the transaction, for the reason in InboxLinkResolver.
        $draft = $this->drafter->draft($command->project, $command->item, '', null);

        $view = $this->em->wrapInTransaction(function () use ($command, $draft): CreateInboxItemView {
            // Serialises every writer of items in the project: the numbers, and the dedupe lookup.
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);
            if (null !== $command->dedupeKey) {
                $open = $this->inboxItems->findOpenByDedupeKey($command->project, $command->dedupeKey);
                if (null !== $open) {
                    return new CreateInboxItemView($open, created: false);
                }
            }

            $now = new \DateTimeImmutable();
            $item = new InboxItem(
                project: $command->project,
                number: $this->inboxItems->nextNumber($command->project),
                kind: $draft->kind,
                title: $draft->title,
                blocking: false,
                origin: null === $command->apiKey ? InboxAskOrigin::Agent : InboxAskOrigin::ApiKey,
                body: $draft->body,
                options: $draft->options,
                multiple: $draft->multiple,
                freeText: $draft->freeText,
                createdAt: $now,
                searchLanguage: $command->project->searchLanguage,
            );
            $item->apiKeyId = $command->apiKey?->keyId;
            $item->apiKeyName = $command->apiKey?->keyName;
            $item->dedupeKey = $command->dedupeKey;
            foreach ($draft->cards as $card) {
                $item->cards->add(new InboxItemCard($item, $card, $now));
            }
            foreach ($draft->documents as $document) {
                $item->documents->add(new InboxItemDocument($item, $document, $now));
            }
            $this->em->persist($item);

            $ask = new InboxAsk(project: $command->project, sessionId: null, bridgeId: null, createdAt: $now);
            $ask->items->add(new InboxAskItem($ask, $item, $now));
            $ask->closedAt = $now;
            $this->em->persist($ask);
            // A closed ask pushes nothing, so the push names the item. The Doctrine transport commits it with the item.
            $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));
            $this->em->flush();
            $this->searchIndexer->index($item);

            return new CreateInboxItemView($item, created: true);
        });

        if (!$view->created) {
            return $view;
        }
        $this->inboxChanged->changed($command->project);
        $this->cardTiles->refresh($view->item);

        // After the commit, for the reason in CreateCardHandler. No title and no body, because a caller wrote them.
        $this->auditor->record(
            'inbox.api_item_created',
            AuditOutcome::Success,
            [
                'itemId' => (string) $view->item->id,
                'itemNumber' => $view->item->number,
                'projectId' => (string) $command->project->id,
                'kind' => $view->item->kind->value,
                'apiKeyId' => null === $command->apiKey ? null : (string) $command->apiKey->keyId,
                'deduplicable' => null !== $command->dedupeKey,
            ],
            new AuditSubject('inbox_item', (string) $view->item->id),
        );

        return $view;
    }
}
