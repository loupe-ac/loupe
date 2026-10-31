<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\TryNowOverrides;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\AccountLimit;
use App\Module\Bridge\ValueObject\WindowLimit;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Inbox\Entity\InboxAccountLimit;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxAccountLimitRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps one open notice for each account that holds open card work of a project at its hard limit,
 * and closes it when the account can take work again or the waiting work ended. An account that
 * is only at its stop value gets no notice, because its runs continue. Running it twice changes
 * nothing, so any trigger may ask again.
 */
final readonly class AccountLimitReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkRequestRepository $workRequests,
        private WorkTakers $workTakers,
        private CardRepository $cards,
        private InboxAccountLimitRepository $inboxAccountLimits,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxChangedPublisher $inboxChanged,
        private InboxAvailability $inbox,
        private TryNowOverrides $tryNowOverrides,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private MessageBusInterface $bus,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public function reconcile(Project $project): void
    {
        $outcome = $this->em->wrapInTransaction(function () use ($project): ?bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $now = self::whole($this->clock->now());
            $enabled = $this->inbox->isEnabled();
            $episodes = $this->inboxAccountLimits->findOpenForProject($project);

            // Doctrine inserts before it updates, so an episode whose item closed elsewhere must end first.
            $ended = false;
            foreach ($episodes as $account => $episode) {
                if (InboxItemState::Open !== $episode->item->state) {
                    $episode->endedAt = $now;
                    unset($episodes[$account]);
                    $ended = true;
                }
            }
            if ($ended) {
                $this->em->flush();
            }

            $limited = $enabled ? $this->limitedAccounts($project) : [];
            $closed = false;
            $opened = false;
            $edited = false;
            $reindex = [];
            $ownerId = $project->owner->id ?? throw new \LogicException('A project owner always has an id.');

            foreach ($episodes as $account => $episode) {
                if (isset($limited[$account])) {
                    continue;
                }
                // A press of Try now lifts the account for a short time. The notice waits for the answer of the next reading.
                if ($enabled && $this->tryNowOverrides->isOpenForAccount($ownerId, $account)) {
                    continue;
                }
                $this->close($episode, $enabled, $now);
                $closed = true;
            }

            foreach ($limited as $account => $row) {
                $episode = $episodes[$account] ?? null;
                if (null === $episode) {
                    $episode = $this->open($project, $account, $row['limit'], $row['cards'], $now);
                    // The next item number reads the database, so the item must reach it first.
                    $this->em->flush();
                    $opened = true;
                    $reindex[] = $episode->item;
                    continue;
                }
                if ($this->extend($episode->item, $row['limit'], $row['cards'], $now)) {
                    $edited = true;
                    $reindex[] = $episode->item;
                }
            }

            if (!$closed && !$opened && !$edited) {
                return null;
            }
            $this->em->flush();
            foreach ($reindex as $item) {
                $this->searchIndexer->index($item);
            }

            return $closed || $opened;
        });

        if (true === $outcome) {
            $this->inboxChanged->changed($project);
        } elseif (false === $outcome) {
            $this->inboxChanged->pageChanged($project);
        }
    }

    /**
     * The accounts at their hard limit that hold open card work, with the cards that wait for them. A request counts
     * for an account only while its wait reads Limited and the account has a window at its limit.
     *
     * @return array<string, array{limit: AccountLimit, cards: array<string, Card>}> by account name
     */
    private function limitedAccounts(Project $project): array
    {
        $requests = [];
        foreach ($this->workRequests->findOpenOfCardsInProject($project) as $request) {
            $requests[($request->id ?? throw new \LogicException('A stored request has an id.'))->toRfc4122()] = $request;
        }

        /** @var array<string, AccountLimit> $limits */
        $limits = [];
        /** @var array<string, array<string, Uuid>> $subjects */
        $subjects = [];
        foreach ($this->workTakers->forRequests($project, array_values($requests)) as $requestId => $reading) {
            if (WorkTakerState::Limited !== $reading->state || null === $reading->limit) {
                continue;
            }
            foreach ($reading->limit->accounts as $limit) {
                if (!$limit->atHardLimit()) {
                    continue;
                }
                $subjectId = $requests[$requestId]->subjectId;
                $subjects[$limit->account][$subjectId->toRfc4122()] = $subjectId;
                $best = $limits[$limit->account] ?? null;
                if (null === $best || ($limit->releaseAt() ?? $limit->windows[0]->reading->resetsAt) > ($best->releaseAt() ?? $best->windows[0]->reading->resetsAt)) {
                    $limits[$limit->account] = $limit;
                }
            }
        }

        $rows = [];
        foreach ($limits as $account => $limit) {
            $cards = [];
            foreach ($this->cards->findByIdsInProject($project, array_values($subjects[$account])) as $card) {
                $cards[($card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122()] = $card;
            }
            uasort($cards, static fn (Card $a, Card $b): int => $a->number <=> $b->number);
            if ([] === $cards) {
                continue;
            }
            $rows[$account] = ['limit' => $limit, 'cards' => $cards];
        }
        ksort($rows);

        return $rows;
    }

    /** @param array<string, Card> $cards */
    private function open(Project $project, string $account, AccountLimit $limit, array $cards, \DateTimeImmutable $now): InboxAccountLimit
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Notice,
            title: $this->title($account, $limit, \count($cards)),
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        foreach ($cards as $card) {
            $item->cards->add(new InboxItemCard($item, $card, $now));
        }
        $item->body = $this->body($item, $account, $limit);
        $episode = new InboxAccountLimit($project, $item, $account, $now);
        $this->em->persist($item);
        $this->em->persist($episode);
        // The Doctrine transport commits the message with the notice.
        $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));

        return $episode;
    }

    /**
     * Links the cards the item does not hold yet and writes its title and body again when a card
     * joined or the text changed.
     *
     * @param array<string, Card> $cards
     */
    private function extend(InboxItem $item, AccountLimit $limit, array $cards, \DateTimeImmutable $now): bool
    {
        $linked = [];
        foreach ($item->cards as $link) {
            $linked[($link->card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122()] = true;
        }
        foreach ($cards as $cardId => $card) {
            if (!isset($linked[$cardId])) {
                $item->cards->add(new InboxItemCard($item, $card, $now));
            }
        }

        $title = $this->title($limit->account, $limit, \count($cards));
        $body = $this->body($item, $limit->account, $limit);
        if ($title === $item->title && $body === $item->body) {
            return false;
        }

        $item->title = $title;
        $item->body = $body;
        $item->updatedAt = $now;

        return true;
    }

    private function title(string $account, AccountLimit $limit, int $cards): string
    {
        $window = $this->limitedWindows($limit)[0];
        $name = $window->reading->window;

        return $this->translator->trans('inbox.account_limit.title', [
            '%account%' => $account,
            '%window%' => \in_array($name, ['session', 'week'], true) ? $this->translator->trans('inbox.account_limit.window.'.$name, [], null, $this->locale) : $name,
            '%count%' => $cards,
            '%resets%' => self::stamp($limit->releaseAt() ?? $window->reading->resetsAt),
        ], null, $this->locale);
    }

    private function body(InboxItem $item, string $account, AccountLimit $limit): string
    {
        $cards = array_map(static fn (InboxItemCard $link): Card => $link->card, $item->cards->toArray());
        usort($cards, static fn (Card $a, Card $b): int => $a->number <=> $b->number);

        $windows = array_map(
            fn (WindowLimit $window): string => '- '.$this->translator->trans('inbox.account_limit.body.window', [
                '%window%' => $this->windowLabel($window->reading->window),
                '%resets%' => self::stamp($window->reading->resetsAt),
            ], null, $this->locale),
            $this->limitedWindows($limit),
        );
        $lines = array_map(
            fn (Card $card): string => '- '.$this->translator->trans('inbox.account_limit.body.card', ['%number%' => $card->number, '%title%' => $card->title], null, $this->locale),
            $cards,
        );

        return $this->translator->trans('inbox.account_limit.body.intro', ['%account%' => $account], null, $this->locale)
            ."\n\n".implode("\n", $windows)
            ."\n\n".$this->translator->trans('inbox.account_limit.body.cards', [], null, $this->locale)
            ."\n\n".implode("\n", $lines)
            ."\n\n".$this->translator->trans('inbox.account_limit.body.remedy', [], null, $this->locale);
    }

    /**
     * The windows at their limit, the latest reset first.
     *
     * @return non-empty-list<WindowLimit>
     */
    private function limitedWindows(AccountLimit $limit): array
    {
        $windows = array_values(array_filter($limit->windows, static fn (WindowLimit $window): bool => WindowLimitState::AtLimit === $window->state));
        usort($windows, static fn (WindowLimit $a, WindowLimit $b): int => $b->reading->resetsAt <=> $a->reading->resetsAt ?: strcmp($a->reading->window, $b->reading->window));

        return [] === $windows ? throw new \LogicException('A notice opens for an account with a window at its limit.') : $windows;
    }

    private function windowLabel(string $window): string
    {
        return \in_array($window, ['session', 'week'], true)
            ? $this->translator->trans('inbox.account_limit.window_label.'.$window, [], null, $this->locale)
            : $window;
    }

    /**
     * The account takes work again when a card of the notice still has live work. The notice is obsolete
     * when none has, because the waiting work ended first.
     */
    private function close(InboxAccountLimit $episode, bool $enabled, \DateTimeImmutable $now): void
    {
        $item = $episode->item;
        $cardIds = array_values(array_map(static fn (InboxItemCard $link): string => ($link->card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122(), $item->cards->toArray()));
        $recovered = $enabled && [] !== $this->workRequests->findLiveRowsForCards($cardIds);

        $note = $recovered ? $this->translator->trans('inbox.account_limit.closed', [
            '%account%' => $episode->account,
            '%start%' => self::stamp($episode->startedAt),
            '%end%' => self::stamp($now),
        ], null, $this->locale) : null;

        $this->closer->close($item, $recovered ? InboxItemState::Done : InboxItemState::Obsolete, $note, $now, InboxEventType::ACTOR_AGENT);
        $episode->endedAt = $now;
    }

    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('D j M, H:i').' UTC';
    }

    /** Whole seconds, like the stored columns. */
    private static function whole(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime((int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s'));
    }
}
