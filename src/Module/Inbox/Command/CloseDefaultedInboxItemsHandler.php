<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxDecisionLog;
use App\Module\Inbox\Service\InboxItemCloser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Answers each open item whose deadline passed with the option the agent recommended. The normal close then
 * resumes the worker, as an answer of the owner does.
 */
final readonly class CloseDefaultedInboxItemsHandler
{
    public const string NOT_DUE = 'inbox.defaulted.error.not_due';

    /** What one pass answers at most. A later pass takes the rest. */
    public const int BATCH = 100;

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxAvailability $inbox,
        private InboxDecisionLog $decisionLog,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int how many items it answered */
    public function __invoke(CloseDefaultedInboxItemsCommand $command): int
    {
        if (!$this->inbox->isEnabled()) {
            return 0;
        }

        $answered = 0;
        foreach ($this->inboxItems->findPastDefaultsAt($command->now, self::BATCH) as $item) {
            $cardIds = [];
            try {
                // Checked inside the lock, against the item as stored: the owner may answer between the query and the lock.
                $this->closer->respond($item, InboxItemState::Resolved, InboxCloser::Loupe, InboxCloseCause::Defaulted, 'selectedOptions', function (InboxItem $item) use ($command, &$cardIds): ?array {
                    $recommended = $item->recommendedOption;
                    if (null === $recommended || null === $item->defaultsAt || $item->defaultsAt > $command->now || !isset($item->options[$recommended])) {
                        return ['selectedOptions' => self::NOT_DUE];
                    }
                    $item->selectedOptions = [$recommended];
                    $item->note = null;

                    // Written in the transaction of the close, so a default leaves an entry on each card the item names.
                    $cards = $item->cards->map(static fn (InboxItemCard $link): Card => $link->card)->toArray();
                    $this->decisionLog->record($item->project, array_values($cards), $item->label ?? '', $recommended, $item->options, InboxDecisionMadeBy::Defaulted, $item);
                    $cardIds = array_values(array_map(static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A stored card has an id.'), $cards));

                    return null;
                });
            } catch (DomainErrors) {
                // Closed by an answer in the meantime, or no longer due.
                continue;
            }

            ++$answered;
            $this->decisionLog->refresh($item->project, $cardIds);
            $this->logger->info('inbox.item_defaulted', ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id, 'label' => $item->label]);
            $this->auditor->record(
                'inbox.item_defaulted',
                AuditOutcome::Success,
                [
                    'itemId' => (string) $item->id,
                    'projectId' => (string) $item->project->id,
                    'label' => $item->label,
                    'option' => $item->selectedOptions[0] ?? null,
                ],
                new AuditSubject('inbox_item', (string) $item->id),
            );
        }

        return $answered;
    }
}
