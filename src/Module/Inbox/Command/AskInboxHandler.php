<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Command\RecordRunAskCommand;
use App\Module\Bridge\Command\RecordRunAskHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\InboxLimits;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\AskingBridge;
use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Inbox\Service\InboxCardTileRefresher;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Inbox\Service\InboxDecisionLog;
use App\Module\Inbox\Service\InboxItemDrafter;
use App\Module\Inbox\Service\InboxRefusal;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Inbox\Service\InboxSessionAsks;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\AskMode;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Hands items to the owner. The items go to the session's open ask, or to a
 * new ask when the session has none.
 */
final readonly class AskInboxHandler
{
    public const string ITEMS_EMPTY = 'inbox.ask.error.items_empty';
    public const string TOO_MANY_ITEMS = 'inbox.ask.error.too_many_items';
    public const string TITLE_BLANK = 'inbox.item.error.title_blank';
    public const string TITLE_TOO_LONG = 'inbox.item.error.title_too_long';
    public const string TITLE_MULTILINE = 'inbox.item.error.title_multiline';
    public const string BODY_TOO_LONG = 'inbox.item.error.body_too_long';
    public const string OPTION_BLANK = 'inbox.item.error.option_blank';
    public const string OPTION_DUPLICATE = 'inbox.item.error.option_duplicate';
    public const string OPTION_TOO_LONG = 'inbox.item.error.option_too_long';
    public const string TOO_MANY_OPTIONS = 'inbox.item.error.too_many_options';
    public const string TOO_MANY_LINKS = 'inbox.item.error.too_many_links';
    public const string TO_DO_WITH_ANSWER = 'inbox.item.error.todo_with_answer';
    public const string NOTICE_WITH_ANSWER = 'inbox.item.error.notice_with_answer';
    public const string QUESTION_WITHOUT_ANSWER = 'inbox.item.error.question_without_answer';
    public const string MULTIPLE_WITHOUT_OPTIONS = 'inbox.item.error.multiple_without_options';
    public const string REVIEW_TARGET_REQUIRED = 'inbox.review.error.target_required';
    public const string REVIEW_TARGET_WRONG_KIND = 'inbox.review.error.target_wrong_kind';
    public const string REVIEW_WITH_ANSWER = 'inbox.review.error.with_answer';
    public const string RECOMMENDED_NEEDS_OPTIONS = 'inbox.item.error.recommended_needs_options';
    public const string RECOMMENDED_OUT_OF_RANGE = 'inbox.item.error.recommended_out_of_range';
    public const string CONFIDENCE_INVALID = 'inbox.item.error.confidence_invalid';
    public const string CONFIDENCE_WITHOUT_RECOMMENDED = 'inbox.item.error.confidence_without_recommended';
    public const string RECOMMENDED_WITHOUT_CONFIDENCE = 'inbox.item.error.recommended_without_confidence';
    public const string COSTS_NEED_OPTIONS = 'inbox.item.error.costs_need_options';
    public const string COSTS_COUNT = 'inbox.item.error.costs_count';
    public const string COST_TOO_LONG = 'inbox.item.error.cost_too_long';
    public const string TOO_MANY_DETAILS = 'inbox.item.error.too_many_details';
    public const string DETAIL_NAME_INVALID = 'inbox.item.error.detail_name_invalid';
    public const string DETAIL_NAME_TOO_LONG = 'inbox.item.error.detail_name_too_long';
    public const string DETAIL_BLANK = 'inbox.item.error.detail_blank';
    public const string DETAIL_TOO_LONG = 'inbox.item.error.detail_too_long';
    public const string LABEL_INVALID = 'inbox.item.error.label_invalid';
    public const string LABEL_NEEDS_QUESTION = 'inbox.item.error.label_needs_question';
    public const string LABEL_NEEDS_BLOCKING = 'inbox.item.error.label_needs_blocking';
    public const string LABEL_NO_MULTIPLE = 'inbox.item.error.label_no_multiple';
    public const string LABEL_NEEDS_RECOMMENDED = 'inbox.item.error.label_needs_recommended';
    public const string LABEL_LOG_NEEDS_CARD = 'inbox.item.error.label_log_needs_card';
    public const string LABEL_FIELD_MISSING = 'inbox.item.error.label_field_missing';
    public const string SUGGESTION_UNKNOWN_ACTION = 'inbox.item.error.suggestion_unknown_action';
    public const string SUGGESTION_DUPLICATE = 'inbox.item.error.suggestion_duplicate';
    public const string SUGGESTION_TWO_RECOMMENDED = 'inbox.item.error.suggestion_two_recommended';
    public const string SUGGESTION_REASON_BLANK = 'inbox.item.error.suggestion_reason_blank';
    public const string SUGGESTION_REASON_TOO_LONG = 'inbox.item.error.suggestion_reason_too_long';
    public const string SUGGESTION_CARD_NOT_LINKED = 'inbox.item.error.suggestion_card_not_linked';
    public const string TOO_MANY_SUGGESTIONS = 'inbox.item.error.too_many_suggestions';
    public const string SUGGESTION_RUN_COMMAND_KIND = 'inbox.item.error.suggestion_run_command_kind';
    public const string SUGGESTION_COMMAND_REQUIRED = 'inbox.item.error.suggestion_command_required';
    public const string SUGGESTION_COMMAND_UNEXPECTED = 'inbox.item.error.suggestion_command_unexpected';
    public const string SUGGESTION_BRANCH_INVALID = 'inbox.item.error.suggestion_branch_invalid';
    public const string SUGGESTION_PARAM_INVALID = 'inbox.item.error.suggestion_param_invalid';
    public const string SUGGESTION_ENTRY_INVALID = 'inbox.item.error.suggestion_entry_invalid';
    public const string SUGGESTION_PROMPT_UNEXPECTED = 'inbox.item.error.suggestion_prompt_unexpected';
    public const string SUGGESTION_PROMPT_TOO_LONG = 'inbox.item.error.suggestion_prompt_too_long';
    public const string SUGGESTION_PROMPT_BLANK = 'inbox.item.error.suggestion_prompt_blank';

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxSessionAsks $sessionAsks,
        private AskingBridge $askingBridge,
        private InboxItemDrafter $drafter,
        private InboxSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private InboxChangedPublisher $inboxChanged,
        private CardWaitTrigger $cardWaits,
        private InboxCardTileRefresher $cardTiles,
        private WorkerRunRepository $workerRuns,
        private RecordRunAskHandler $recordRunAsk,
        private InboxDecisionLog $decisionLog,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AskInboxCommand $command): AskInboxView
    {
        if ([] === $command->items) {
            throw new DomainErrors(['items' => self::ITEMS_EMPTY]);
        }
        if (\count($command->items) > InboxLimits::MAX_ITEMS_PER_CALL) {
            throw new DomainErrors(['items' => self::TOO_MANY_ITEMS]);
        }

        // Every refusal it can decide alone comes before the transaction, for the
        // reason in InboxLinkResolver.
        $bridge = array_any($command->items, static fn (AskInboxItem $input): bool => array_any($input->suggestedActions, static fn (AskInboxSuggestion $suggestion): bool => InboxActionKind::RunCommand->value === $suggestion->action))
            ? $this->askingBridge->find($command->project, $command->sessionId, $command->bridgeId)
            : null;
        $drafts = [];
        $logged = [];
        $loggedDrafts = [];
        foreach ($command->items as $index => $input) {
            $draft = $this->drafter->draft($command->project, $input, \sprintf('items[%d].', $index), $bridge);
            if (AskMode::Log === $draft->mode) {
                if ([] === $draft->cards && !$this->cardOfAskingRun($command) instanceof Card) {
                    throw new DomainErrors([\sprintf('items[%d].label', $index) => self::LABEL_LOG_NEEDS_CARD]);
                }
                $logged[] = new AskInboxLogged($draft->label ?? '', $draft->title, $draft->options[$draft->recommendedOption ?? 0]);
                $loggedDrafts[] = $draft;

                continue;
            }
            $drafts[] = $draft;
        }
        if (null !== $command->context && mb_strlen($command->context) > InboxLimits::MAX_CONTEXT_LENGTH) {
            throw new DomainErrors(['context' => InboxSessionAsks::CONTEXT_TOO_LONG]);
        }
        $context = null === $command->context || '' === trim($command->context) ? null : trim($command->context);

        // A call that logs every item opens no ask and writes no item, so nothing waits and no one is asked.
        if ([] === $drafts) {
            $cardIds = $this->em->wrapInTransaction(fn (): array => $this->logDecisions($command, $loggedDrafts));
            $this->decisionLog->refresh($command->project, $cardIds);
            $this->logger->info('inbox.items_logged', ['projectId' => (string) $command->project->id, 'sessionId' => (string) $command->sessionId, 'labels' => implode(',', array_map(static fn (AskInboxLogged $entry): string => $entry->label, $logged))]);

            return new AskInboxView(null, [], extended: false, logged: $logged);
        }

        try {
            $loggedCardIds = [];
            $view = $this->em->wrapInTransaction(function () use ($command, $drafts, $context, $logged, $loggedDrafts, &$loggedCardIds): AskInboxView|InboxRefusal {
                // Serialises every ask in the project: the item numbers, and the
                // lookup of the session's open ask that decides open or extend.
                $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

                $sessionAsk = $this->sessionAsks->openOrExtend($command->project, $command->sessionId, $command->bridgeId, $context);
                if ($sessionAsk instanceof InboxRefusal) {
                    return $sessionAsk;
                }

                $now = new \DateTimeImmutable();
                $number = $this->inboxItems->nextNumber($command->project);
                $created = [];
                foreach ($drafts as $draft) {
                    $item = new InboxItem(
                        project: $command->project,
                        number: $number++,
                        kind: $draft->kind,
                        title: $draft->title,
                        blocking: $draft->blocking,
                        origin: InboxAskOrigin::Agent,
                        body: $draft->body,
                        options: $draft->options,
                        multiple: $draft->multiple,
                        freeText: $draft->freeText,
                        createdAt: $now,
                        searchLanguage: $command->project->searchLanguage,
                    );
                    $item->suggestedActions = [] === $draft->suggestedActions ? null : $draft->suggestedActions;
                    $item->dismissible = $draft->dismissible;
                    $item->recommendedOption = $draft->recommendedOption;
                    $item->confidence = $draft->confidence;
                    $item->optionCosts = $draft->optionCosts;
                    $item->details = $draft->details;
                    $item->label = $draft->label;
                    $item->defaultsAt = AskMode::Default === $draft->mode && null !== $draft->defaultHours
                        ? $now->modify(\sprintf('+%d hours', $draft->defaultHours))
                        : null;
                    foreach ($draft->cards as $card) {
                        $item->cards->add(new InboxItemCard($item, $card, $now));
                    }
                    foreach ($draft->documents as $document) {
                        $item->documents->add(new InboxItemDocument($item, $document, $now));
                    }
                    $this->em->persist($item);
                    if (null !== $draft->reviewTarget) {
                        $this->em->persist(new InboxReview($item, $draft->reviewTarget));
                    }
                    $this->sessionAsks->add($sessionAsk->ask, $item);
                    $created[] = $item;
                }

                $this->sessionAsks->closeWhenNothingBlocks($sessionAsk, $now);
                // Only an ask this call opened and left open is news. The Doctrine transport commits the message with the ask.
                if ($sessionAsk->opened && null === $sessionAsk->ask->closedAt) {
                    $this->bus->dispatch(new NotifyInboxOwner(($sessionAsk->ask->id ?? throw new \LogicException('A persisted ask has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Ask));
                }
                $this->linkRunToAsk($sessionAsk->ask, $drafts);
                $loggedCardIds = $this->logDecisions($command, $loggedDrafts);
                $this->em->flush();

                foreach ($created as $item) {
                    $this->searchIndexer->index($item);
                }

                return new AskInboxView($sessionAsk->ask, $created, extended: !$sessionAsk->opened, logged: $logged);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The project lock serialises one project only. A session that asks in
            // two projects at once meets the one-open-ask index instead.
            if (!str_contains($e->getMessage(), InboxSessionAsks::OPEN_SESSION_INDEX)) {
                throw $e;
            }

            throw new DomainErrors(['sessionId' => InboxSessionAsks::SESSION_ASK_ELSEWHERE]);
        }

        if ($view instanceof InboxRefusal) {
            throw new DomainErrors([$view->field => $view->key]);
        }
        $ask = $view->ask ?? throw new \LogicException('An ask that kept an item has an ask.');
        $this->inboxChanged->changed($command->project, [$ask->id ?? throw new \LogicException('A stored ask has an id.')]);
        $this->cardTiles->refresh(...$view->items);
        $this->decisionLog->refresh($command->project, $loggedCardIds, signalInbox: false);
        $reviewed = [];
        foreach ($drafts as $draft) {
            if ($draft->reviewTarget instanceof Document) {
                $reviewed[(string) $draft->reviewTarget->id] = $draft->reviewTarget;
            }
        }
        foreach ($reviewed as $document) {
            $this->cardWaits->forDocument($document->id ?? throw new \LogicException('Document has no id.'));
        }

        // After the commit, for the reason in CreateCardHandler. No title and no
        // body, because they are sentences an agent wrote.
        $this->auditor->record(
            'inbox.items_asked',
            AuditOutcome::Success,
            [
                'askId' => (string) $ask->id,
                'projectId' => (string) $command->project->id,
                'sessionId' => (string) $command->sessionId,
                'bridgeId' => null === $command->bridgeId ? null : (string) $command->bridgeId,
                'itemNumbers' => implode(',', array_map(static fn (InboxItem $item): int => $item->number, $view->items)),
                'extended' => $view->extended,
                'closed' => null !== $ask->closedAt,
                'logged' => \count($logged),
            ],
            new AuditSubject('inbox_ask', (string) $ask->id),
        );

        return $view;
    }

    /**
     * Writes one decision entry for each card a logged ask names, and flushes. An ask that names no card
     * belongs to the card of the run that asks, found as linkRunToAsk() finds it. The call refuses an ask with neither.
     *
     * @param list<AskInboxDraft> $loggedDrafts
     *
     * @return list<Uuid> the cards that got an entry
     */
    private function logDecisions(AskInboxCommand $command, array $loggedDrafts): array
    {
        $cardIds = [];
        foreach ($loggedDrafts as $draft) {
            $cards = $draft->cards;
            if ([] === $cards) {
                $runCard = $this->cardOfAskingRun($command);
                $cards = null === $runCard ? [] : [$runCard];
            }
            if ([] === $cards) {
                throw new \LogicException('A logged ask names a card.');
            }
            $this->decisionLog->record($command->project, $cards, $draft->label ?? '', $draft->recommendedOption ?? 0, $draft->options, InboxDecisionMadeBy::Logged);
            foreach ($cards as $card) {
                $cardIds[(string) $card->id] = $card->id ?? throw new \LogicException('A stored card has an id.');
            }
        }
        $this->em->flush();

        return array_values($cardIds);
    }

    private function cardOfAskingRun(AskInboxCommand $command): ?Card
    {
        if (null === $command->bridgeId) {
            return null;
        }
        $cardId = $this->workerRuns->findLatestOfSessionOnBridge($command->project, $command->bridgeId, $command->sessionId)?->cardId();

        $card = null === $cardId ? null : $this->em->find(Card::class, $cardId);

        return $card?->project === $command->project ? $card : null;
    }

    /**
     * A blocking question about the card of the run stands for the stop of that run, so the run records the ask.
     * Only an ask with a bridge resumes the worker, and only a run that still runs can be the one that asks.
     *
     * @param list<AskInboxDraft> $drafts
     */
    private function linkRunToAsk(InboxAsk $ask, array $drafts): void
    {
        if (null === $ask->bridgeId || null === $ask->sessionId) {
            return;
        }
        $run = $this->workerRuns->findLatestOfSessionOnBridge($ask->project, $ask->bridgeId, $ask->sessionId);
        $cardId = $run?->cardId();
        if (null === $run || null === $cardId || !$run->state->isOpen()) {
            return;
        }
        if (!array_any($drafts, static fn (AskInboxDraft $draft): bool => InboxItemKind::Question === $draft->kind && $draft->blocking && array_any($draft->cards, static fn (Card $card): bool => $cardId->equals($card->id)))) {
            return;
        }

        ($this->recordRunAsk)(new RecordRunAskCommand($run, $ask->id ?? throw new \LogicException('A persisted ask has an id.')));
    }
}
