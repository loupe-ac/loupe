<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxOpenPushTags;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\PushContent;
use App\Module\Notification\Service\Notifier;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\ReferenceTokens;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pushes a new ask or notice to the owner of its project. It reads the ask again, because the message
 * can run after the ask closed.
 */
#[AsMessageHandler]
final readonly class NotifyInboxOwnerHandler
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private InboxOpenPushTags $openTags,
        private Notifier $notifier,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ReferenceTokens $tokens,

        #[Autowire(param: 'app.url')]
        private string $appUrl,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public function __invoke(NotifyInboxOwner $message): void
    {
        $subjectId = Uuid::fromString($message->subjectId);

        if (NotifyInboxOwnerKind::Ask === $message->kind) {
            $this->notifyAsk($subjectId);

            return;
        }

        $this->notifyNotice($subjectId);
    }

    private function notifyAsk(Uuid $askId): void
    {
        $ask = $this->inboxAsks->find($askId);
        if (null === $ask || null !== $ask->closedAt) {
            return;
        }

        $items = array_values(array_map(static fn (InboxAskItem $askItem): InboxItem => $askItem->item, $ask->items->toArray()));
        $questions = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemKind::Question === $item->kind));
        $shown = [] === $questions ? $items : $questions;
        if ([] === $shown) {
            return;
        }

        $body = $this->tokens->plain($shown[0]->title);
        $more = [] === $questions ? 0 : \count($questions) - 1;
        if ($more > 0) {
            $body .= ' '.$this->translator->trans('inbox.push.more_questions', ['%count%' => $more], null, $this->locale);
        }

        $this->send(
            $ask->project,
            $body,
            ['id' => (string) $ask->project->id, 'ask' => $askId->toRfc4122()],
            InboxOpenPushTags::askTag($askId),
            NotificationEvent::BlockingInboxItem,
        );
    }

    private function notifyNotice(Uuid $itemId): void
    {
        $item = $this->inboxItems->find($itemId);
        if (null === $item || InboxItemState::Open !== $item->state) {
            return;
        }

        $this->send(
            $item->project,
            $this->tokens->plain($item->title),
            ['id' => (string) $item->project->id, 'item' => $item->number],
            InboxOpenPushTags::itemTag($itemId),
            NotificationEvent::OtherInboxItem,
        );
    }

    /** @param array<string, string|int> $routeParameters */
    private function send(Project $project, string $body, array $routeParameters, string $tag, NotificationEvent $event): void
    {
        $url = rtrim($this->appUrl, '/').$this->urlGenerator->generate('app_project_inbox', $routeParameters);
        $openTags = array_values(array_unique([$tag, ...$this->openTags->forUser($project->owner)]));

        $this->notifier->notify($project->owner, $event, new PushContent($project->name, $body, $url, $tag, $openTags));
    }
}
