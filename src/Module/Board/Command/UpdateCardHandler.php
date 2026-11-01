<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Event\CardArtifactsChanged;
use App\Module\Board\Event\CardBlockersChanged;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardDocumentsChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Event\CardPullRequestsChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\ArtifactLinkResolver;
use App\Module\Board\Service\CardLinkResolver;
use App\Module\Board\Service\CardLinkSync;
use App\Module\Board\Service\CardMover;
use App\Module\Board\Service\CardParentPolicy;
use App\Module\Board\Service\CardParentResolver;
use App\Module\Board\Service\CardValueWriter;
use App\Module\Board\Service\ChildDesignChoices;
use App\Module\Board\Service\ChildDesignDecision;
use App\Module\Board\Service\DocumentLinkResolver;
use App\Module\Board\Service\HumanReviewSwitch;
use App\Module\Board\Service\PullRequestTracking;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Bridge\Service\CardPause;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** The only handler that moves a card; MoveCardHandler is a shell over it. */
final readonly class UpdateCardHandler
{
    public const string COLUMN_GONE = 'board.card.error.column_gone';
    public const string LINKED_CARD_GONE = 'board.card.error.linked_card_unknown';
    public const string CONTENT_CHANGED = 'board.card.error.changed_since_opened';
    public const string COLUMN_CHANGED = 'board.card.error.column_changed';
    public const string HUMAN_REVIEW_RAISE_ONLY = 'board.card.error.human_review_raise_only';
    public const string PARENT_CLEAR_CONFLICT = 'board.card.error.parent_clear_conflict';
    private const string NOT_WHERE_EXPECTED = 'not_where_expected';

    public function __construct(
        private CardRepository $cards,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardMover $mover,
        private PullRequestUrlResolver $pullRequests,
        private PullRequestTracking $pullRequestTracking,
        private DocumentLinkResolver $documentLinks,
        private ArtifactLinkResolver $artifactLinks,
        private CardLinkResolver $cardLinks,
        private CardLinkSync $cardLinkSync,
        private CardParentResolver $parents,
        private CardParentPolicy $parentPolicy,
        private CardTypeCatalog $catalog,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private InteractiveRuns $interactiveRuns,
        private CardMoveGuard $moveGuard,
        private CardPause $cardPause,
        private HumanReviewSwitch $humanReviewSwitch,
        private CardValueWriter $cardValues,
        private ChildDesignDecision $childDesign,
        private ChildDesignChoices $childChoices,
    ) {
    }

    /**
     * @throws DomainErrors       when the command clears the parent and names one
     * @throws ChildDesignRefused when the child design choice is wrong, or the workflow refuses it
     */
    public function __invoke(UpdateCardCommand $command): UpdateCardView
    {
        $parentCardId = $command->parentCardId;
        if ($command->clearParent) {
            if (null !== $parentCardId && '' !== $parentCardId) {
                throw new DomainErrors(['parentCardId' => self::PARENT_CLEAR_CONFLICT]);
            }
            $parentCardId = '';
        }
        if (null === $command->childDesign && !$command->requireChildDesign) {
            return $this->update($command, $parentCardId);
        }

        $card = $command->card;
        $choice = $this->childDesign->resolve(
            $card->project,
            $parentCardId,
            $card,
            $command->childDesign,
            null === $command->documentIds ? $this->linkedDocumentIds($card) : array_values($command->documentIds),
            null !== $command->column,
        );
        if (null === $choice) {
            return $this->update($command, $parentCardId);
        }

        $view = null;
        $this->childChoices->write(
            $card->project,
            function () use ($command, $parentCardId, &$view): Card {
                $view = $this->update($command, $parentCardId);

                return $view->card;
            },
            $choice,
            $command->cause,
            null === $parentCardId && null === $command->column ? $card : null,
        );

        return $view ?? throw new \LogicException('The write ran the update.');
    }

    /** @return list<string> */
    private function linkedDocumentIds(Card $card): array
    {
        return array_values(array_map(static fn ($link): string => (string) $link->document->id, $card->documents->toArray()));
    }

    private function update(UpdateCardCommand $command, ?string $parentCardId): UpdateCardView
    {
        $card = $command->card;

        $title = null;
        if (null !== $command->title) {
            $title = trim($command->title);
            if ('' === $title) {
                throw new DomainErrors(['title' => 'board.card.error.title_blank']);
            }
            if (mb_strlen($title) > Card::MAX_TITLE_LENGTH) {
                throw new DomainErrors(['title' => 'board.card.error.title_too_long']);
            }
        }

        foreach ($command->pullRequestUrls ?? [] as $url) {
            if (mb_strlen(trim($url)) > CardPullRequest::MAX_URL_LENGTH) {
                throw new DomainErrors(['pullRequestUrls' => 'board.card.error.pull_request_url_too_long']);
            }
        }

        // Outside the transaction, for the reason in CreateCardHandler.
        $documents = null === $command->documentIds
            ? null
            : $this->documentLinks->resolve($card->project, array_values($command->documentIds));
        $artifacts = null === $command->artifactIds
            ? null
            : $this->artifactLinks->resolve($card->project, array_values($command->artifactIds));
        $relatedCards = null === $command->relatedCards
            ? null
            : $this->cardLinks->resolve($card->project, $card, array_values($command->relatedCards));
        $newParent = null === $parentCardId ? null : $this->parents->resolve($card->project, $parentCardId);

        // One write for the whole update, and one lock. A column change is a
        // move, which renumbers a column and decides the completion timestamp,
        // so this handler owns the transaction the move runs in.
        // Flushing the fields first would commit half an update whose move
        // then failed.
        $outcome = $this->em->wrapInTransaction(function () use ($command, $parentCardId, $card, $title, $documents, $artifacts, $relatedCards, $newParent): UpdateCardOutcome|string|DomainErrors|EpicChildrenOpen|CardManaged {
            $this->projects->lockForWrite($card->project);
            // The lock takes the project row, waits for no key share, and leaves the loaded card as the
            // request read it, which may be before the caller ahead of us in
            // the queue committed. Both the decision below and the move it
            // makes read the column, so both need the column as it is now.
            $this->cards->refreshColumn($card);
            if (null !== $command->expectedColumn && $command->expectedColumn !== $card->column) {
                return self::COLUMN_CHANGED;
            }
            // The text too, so the clash check and the change flags below
            // compare against what the last writer committed.
            $this->cards->refreshContent($card);
            if (null !== $command->expectedFingerprint
                && !$command->confirmOverwrite
                && $command->expectedFingerprint !== Card::contentFingerprint($card->title, $card->body)) {
                return self::CONTENT_CHANGED;
            }
            // Before any write, so the refusal commits nothing.
            if ($command->onlyRaiseHumanReview && false === $command->requiresHumanReview) {
                $this->cards->refreshHumanReview($card);
                if ($card->requiresHumanReview) {
                    return new DomainErrors(['requiresHumanReview' => self::HUMAN_REVIEW_RAISE_ONLY]);
                }
            }
            if (null !== $command->values) {
                $refusal = $this->cardValues->refusal($card, $command->values);
                if (null !== $refusal) {
                    return $refusal;
                }
            }
            // The columns too: one deleted or given another terminal flag since
            // the request loaded it decides where the card may go and whether
            // the move stamps it.
            $columns = $this->boardColumns->findForProjectFresh($card->project);
            if ((null !== $command->onlyFromColumn && $card->column !== $command->onlyFromColumn)
                || ($command->onlyFromOpenColumn && $card->column->terminal)) {
                return self::NOT_WHERE_EXPECTED;
            }

            $column = $command->column ?? $card->column;
            if ($column->project !== $card->project) {
                throw new \LogicException('A card moves only to a column of its own board.');
            }
            if (!\in_array($column, $columns, true)) {
                return self::COLUMN_GONE;
            }
            if (null !== $relatedCards && $this->cardLinkSync->anyCardGone($relatedCards)) {
                return self::LINKED_CARD_GONE;
            }
            // Only a write that names a type or a parent can break a parent
            // rule, so a plain move pays no extra read.
            $oldParent = $card->parent;
            $parentChanged = false;
            if (null !== $parentCardId || null !== $command->type) {
                $this->cards->refreshTypeAndParent($card);
                $oldParent = $card->parent;
                $parent = null === $parentCardId ? $oldParent : $newParent;
                $parentChanged = $parent?->id?->toRfc4122() !== $oldParent?->id?->toRfc4122();
                $refusal = $this->parentPolicy->refusal($card->project, $card, $command->type ?? $card->type, $parent, $parentChanged);
                if (null !== $refusal) {
                    return $refusal;
                }
                $card->parent = $parent;
            } elseif (null !== $command->laneEnabled || null !== $command->column || null !== $title || null !== $command->requiresHumanReview) {
                // A lane toggle or a move decides whether a lane shows, from the committed setting.
                // A rename tells the committed parent, which a concurrent reparent may have changed.
                // The switch stores the artifacts the committed parent lends.
                $this->cards->refreshTypeAndParent($card);
            }
            // After the parent step, so the guard reads the parent the move keeps or sets.
            // A card the update holds is unmanaged, so the guard has nothing to refuse.
            $needsHold = $column !== $card->column && !$this->moveGuard->allows($card->snapshot(), $column->ref(), $command->actor, $command->cause);
            if ($needsHold && null === $command->unmanageBy) {
                // A returned refusal commits, so the parent goes back first.
                $card->parent = $oldParent;

                return new CardManaged($card->number);
            }
            $laneChanged = null !== $command->laneEnabled && $command->laneEnabled !== $card->laneEnabled;
            $types = $this->catalog->forProject($card->project->requireId());
            $lanesBefore = $card->drawsLane($types);

            // Only a card with children can be refused, and only an epic has
            // children. The app itself closes an epic by the same path.
            if ($column->terminal && $column !== $card->column && Actor::System !== $command->actor) {
                $open = $this->cards->openChildNumbers($card);
                if ([] !== $open) {
                    return new EpicChildrenOpen($open);
                }
            }

            // After every refusal that returns a value, because a value commits.
            $held = $needsHold && null !== $command->unmanageBy && $this->cardPause->take(
                $card->project,
                $card->id ?? throw new \LogicException('A persisted card has an id.'),
                $command->unmanageBy,
                Actor::Agent === $command->actor ? 'agent' : 'human',
            );

            // A terminal column keeps no rank, so it reads no neighbour.
            $position = $column->terminal || (null === $command->beforeCardId && null === $command->afterCardId)
                ? $command->position
                : $this->neighbourRank($card, $column, $command->beforeCardId, $command->afterCardId);

            // A rank is a move of its own: a card dropped elsewhere in the
            // column it already sits in does not change its column.
            $move = $column !== $card->column || null !== $position
                ? $this->mover->move($card, $column, $position)
                : null;

            // After the move, which closes the runs of a card that changes
            // column, so the run this update opens is not the one closed.
            $openedRun = null;
            if (null !== $command->openInteractiveRun) {
                $cardId = $card->id ?? throw new \LogicException('A persisted card has an id.');
                $openedRun = $this->interactiveRuns->open($card->project, $cardId, $card->number, $command->openInteractiveRun->sessionId, $command->openInteractiveRun->name);
            }

            // After the move, which must read the card as the database holds
            // it. A field the command carries may hold what the card already
            // holds, so the record reports what changed rather than what was
            // submitted.
            $titleChanged = null !== $title && $title !== $card->title;
            $bodyChanged = null !== $command->body && $command->body !== $card->body;
            // The web form trims and turns CRLF into LF, so a save of unchanged
            // text still rewrites the body. Only a change a reader sees counts.
            $contentChanged = (null !== $title && Card::normalText($title) !== Card::normalText($card->title))
                || (null !== $command->body && Card::normalText($command->body) !== Card::normalText($card->body));
            $typeChanged = null !== $command->type && $command->type !== $card->type;

            if (null !== $title) {
                $card->title = $title;
            }
            if (null !== $command->body) {
                $card->body = $command->body;
            }
            if (null !== $command->type) {
                $card->type = $command->type;
            }
            if (null !== $command->laneEnabled) {
                $card->laneEnabled = $command->laneEnabled;
            }
            if (null !== $documents) {
                $card->syncDocuments(...$documents);
            }
            if (null !== $artifacts) {
                $card->syncArtifacts(...$artifacts);
            }
            $humanReviewChanged = false;
            if (null !== $command->requiresHumanReview) {
                // The committed value, so a second request that sets the same value writes no second row.
                $this->cards->refreshHumanReview($card);
                $humanReviewChanged = $this->humanReviewSwitch->set($card, $command->requiresHumanReview, $command->actor, $command->humanReviewCause, $command->humanReviewRuleId);
            }
            $valuesChanged = false;
            foreach ($command->values ?? [] as $key => $value) {
                $valuesChanged = $this->cardValues->set($card, (string) $key, $value, $command->actor) || $valuesChanged;
            }
            $trackedBefore = null;
            if (null !== $command->pullRequestUrls) {
                $trackedBefore = $this->pullRequestTracking->referencesOf($card);
                $card->replacePullRequests(...$this->pullRequests->linksFor($card, array_values($command->pullRequestUrls)));
            }
            $blockersChanged = null === $relatedCards ? [] : $this->cardLinkSync->sync($card, $relatedCards);

            $card->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
            $lanesAfter = $card->drawsLane($types);

            if (null !== $trackedBefore) {
                $changedPullRequests = $this->pullRequestTracking->apply($card->project, $trackedBefore, $this->pullRequestTracking->referencesOf($card));
                if ([] !== $changedPullRequests) {
                    $this->events->dispatch(new CardPullRequestsChanged(
                        $card->project->id ?? throw new \LogicException('A card project is persisted.'),
                        $card->id ?? throw new \LogicException('A persisted card has an id.'),
                        $changedPullRequests,
                    ));
                }
            }

            // Inside the transaction, so a listener's rows land in the same
            // commit: nothing survives a rollback, and nothing is lost when the
            // process dies after it.
            if (null !== $move) {
                $cause = $command->cause ?? (null === $openedRun ? null : CardEventCause::run($openedRun->id ?? throw new \LogicException('A persisted run has an id.'), $openedRun->workKind));
                $this->events->dispatch(new CardMoved($card, $move, $command->actor, $cause));
            }
            if (null !== $documents) {
                $this->events->dispatch(new CardDocumentsChanged(
                    $card->project->id ?? throw new \LogicException('Project has no id.'),
                    $card->id ?? throw new \LogicException('Card has no id.'),
                ));
            }
            if ($parentChanged) {
                $this->events->dispatch(new CardParentChanged($card, $oldParent, $card->parent, $command->actor));
            }
            if ([] !== $blockersChanged) {
                $this->events->dispatch(new CardBlockersChanged($card->project, $blockersChanged, $command->actor));
            }

            return new UpdateCardOutcome($move, $titleChanged, $bodyChanged, $typeChanged, $parentChanged, $laneChanged, $lanesBefore !== $lanesAfter, $contentChanged, $openedRun, $held, $humanReviewChanged, $valuesChanged);
        });

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        if ($outcome instanceof DomainErrors || $outcome instanceof EpicChildrenOpen || $outcome instanceof CardManaged) {
            throw $outcome;
        }
        if (self::NOT_WHERE_EXPECTED === $outcome) {
            return new UpdateCardView($card, null);
        }
        if (\is_string($outcome)) {
            $field = match ($outcome) {
                self::LINKED_CARD_GONE => 'relatedCards',
                self::CONTENT_CHANGED => 'contentFingerprint',
                default => 'column',
            };
            throw new DomainErrors([$field => $outcome]);
        }

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The move comes
        // first, so the pair reads in the order the board applied it.
        if ($outcome->held) {
            $this->cardPause->announce($card->project, $card->id ?? throw new \LogicException('A persisted card has an id.'));
        }
        if (null !== $outcome->move) {
            $this->auditor->record(
                'board.card_moved',
                AuditOutcome::Success,
                $outcome->move->auditContext($card),
                new AuditSubject('card', (string) $card->id),
            );
        }

        // A replacement is a deliberate act even when the new list matches the
        // old one, so a submitted list counts as a change. A call that only
        // moves the card records the move alone, rather than an update whose
        // every flag is false.
        $changedSomething = $outcome->titleChanged
            || $outcome->bodyChanged
            || $outcome->typeChanged
            || $outcome->parentChanged
            || $outcome->laneChanged
            || $outcome->humanReviewChanged
            || $outcome->valuesChanged
            || null !== $command->pullRequestUrls
            || null !== $command->documentIds
            || null !== $command->artifactIds
            || null !== $command->relatedCards;

        if ($changedSomething || null !== $outcome->move) {
            $this->events->dispatch(new CardChanged(
                $card->project->id ?? throw new \LogicException('Project has no id.'),
                $card->id ?? throw new \LogicException('Card has no id.'),
                CardChanged::UPDATED,
                $outcome->contentChanged,
            ));
        }
        if (null !== $command->artifactIds) {
            $this->events->dispatch(new CardArtifactsChanged(
                $card->project->id ?? throw new \LogicException('Project has no id.'),
                $card->id ?? throw new \LogicException('Card has no id.'),
            ));
        }
        // An open blocker lends its title to the Waiting reason of each card it blocks.
        if ($outcome->titleChanged && !$card->column->terminal) {
            foreach ($this->cards->findBlockedBy($card) as $blocked) {
                $this->events->dispatch(new CardChanged(
                    $card->project->id ?? throw new \LogicException('Project has no id.'),
                    $blocked->id ?? throw new \LogicException('Card has no id.'),
                    CardChanged::UPDATED,
                    false,
                ));
            }
            // An open child lends its title to the child marker of its epic.
            if (null !== $card->parent) {
                $this->events->dispatch(new CardChanged(
                    $card->project->id ?? throw new \LogicException('Project has no id.'),
                    $card->parent->id ?? throw new \LogicException('Card has no id.'),
                    CardChanged::UPDATED,
                    false,
                ));
            }
        }
        // A lane adds or removes a board row, which no placement of one card shows.
        if ($outcome->laneShownChanged) {
            $this->events->dispatch(new BoardColumnsChanged($card->project));
        }

        $view = new UpdateCardView($card, $outcome->openedRun);
        if (!$changedSomething) {
            return $view;
        }

        // `moved` names the paired board.card_moved record, which holds the
        // column this one does not.
        $this->auditor->record(
            'board.card_updated',
            AuditOutcome::Success,
            [
                'cardId' => (string) $card->id,
                'cardNumber' => $card->number,
                'projectId' => (string) $card->project->id,
                'titleChanged' => $outcome->titleChanged,
                'bodyChanged' => $outcome->bodyChanged,
                'typeChanged' => $outcome->typeChanged,
                'parentChanged' => $outcome->parentChanged,
                'laneChanged' => $outcome->laneChanged,
                'humanReviewChanged' => $outcome->humanReviewChanged,
                'valuesChanged' => $outcome->valuesChanged,
                'pullRequestsReplaced' => null !== $command->pullRequestUrls,
                'documentsReplaced' => null !== $command->documentIds,
                'artifactsReplaced' => null !== $command->artifactIds,
                'relatedCardsReplaced' => null !== $command->relatedCards,
                'moved' => null !== $outcome->move,
            ],
            new AuditSubject('card', (string) $card->id),
        );

        return $view;
    }

    /**
     * The rank next to a neighbour, counted among the other cards of the
     * column, which is the list CardGroupOrder::place() opens a gap in. Call it
     * under the lock: the SQL order is fresh even when a loaded rank is stale.
     */
    private function neighbourRank(Card $card, BoardColumn $column, ?string $beforeCardId, ?string $afterCardId): int
    {
        $others = array_values(array_filter(
            $this->cards->findRanked($column),
            static fn (Card $member): bool => $member !== $card,
        ));
        $ids = array_map(static fn (Card $member): ?string => $member->id?->toRfc4122(), $others);

        $before = null === $beforeCardId ? false : array_search(strtolower(trim($beforeCardId)), $ids, true);
        if (\is_int($before)) {
            return $before;
        }
        $after = null === $afterCardId ? false : array_search(strtolower(trim($afterCardId)), $ids, true);

        // A neighbour of another column, of another project, or deleted since.
        return \is_int($after) ? $after + 1 : CardMover::END_OF_COLUMN;
    }
}
