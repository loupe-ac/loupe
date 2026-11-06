<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Api\Fields\FieldSelection;
use App\Module\Board\Command\CardView;
use App\Module\Board\Command\RelatedCard;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardArtifactRepository;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Board\Service\CardMarkerText;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\CardValueReader;
use App\Module\Board\Service\PullRequestStates;
use App\Module\Board\Service\PullRequestStateView;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Board\View\CardValueView;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\StoredMarker;
use Symfony\Component\Uid\Uuid;

/**
 * The one shape every board tool returns a card in, so a card read by card_list
 * and a card read by card_get describe themselves the same way.
 *
 * @phpstan-import-type AgentReviewSummary from CardAgentReviews
 *
 * @phpstan-type PullRequestStateSummary array{state: string, draft: bool, checks: string, failedChecks: list<string>, mergeability: string, review: string, readyToMerge: bool, refreshedAt: string}
 * @phpstan-type CardPullRequestSummary array{pullRequestId: string, url: string, forge: string, repository: ?string, number: ?int, state: ?PullRequestStateSummary, agentReview: ?AgentReviewSummary}
 * @phpstan-type FeedbackAnchorSummary array{selector: string, text: string, quote: ?string, quotePrefix: ?string, quoteSuffix: ?string}
 * @phpstan-type ScreenshotSize array{width: int, height: int}
 * @phpstan-type FeedbackSummary array{id: string, url: ?string, surface: string, native: ?array<string, mixed>, screenshot: ?ScreenshotSize, anchors: list<FeedbackAnchorSummary>, body: string, hasDrawing: bool, status: string, context: ?string, createdAt: string}
 * @phpstan-type CardDocumentSummary array{documentId: string, title: string, status: string}
 * @phpstan-type CardArtifactSummary array{artifactId: string, name: string, versionNumber: int, archived: bool, path: string}
 * @phpstan-type CardRelatedCardSummary array{cardId: string, number: int, title: string, status: string, kind: string}
 * @phpstan-type CardPauseSummary array{pauseId: string, kind: string, reason: string, ruleId: string, since: string}
 * @phpstan-type CardRefSummary array{cardId: string, number: int, title: string, status: string}
 * @phpstan-type CardStateReasonSummary array{kind: string, code: string, reason: string, since: string}
 * @phpstan-type CardStateSummary array{kind: string, code: string, since: string, reason: string, others: list<CardStateReasonSummary>}
 * @phpstan-type CardListStateSummary array{kind: string, code: string, since: string}
 * @phpstan-type CardValueSummary array{key: string, type: string, visibility: string, value: mixed, writer: ?array{kind: string, name: ?string}, updatedAt: ?string}
 * @phpstan-type CardSummary array{cardId: string, number: int, title: string, body: string, type: string, status: string, reporter: string, position: int, completedAt: ?string, createdAt: string, updatedAt: string, pullRequests: list<CardPullRequestSummary>, documents: list<CardDocumentSummary>, artifacts: list<CardArtifactSummary>, siteReviewComments: list<FeedbackSummary>, relatedCards: list<CardRelatedCardSummary>, parent: ?CardRefSummary, laneEnabled: bool, requiresHumanReview: bool, values: list<CardValueSummary>, children: list<CardRefSummary>, progress: ?array{done: int, total: int}, pause: ?CardPauseSummary, state: ?CardStateSummary}
 * @phpstan-type CardListSummary array{cardId: string, number: int, title: string, type: string, status: string, reporter: string, parentCardId: ?string, updatedAt: string, state: ?CardListStateSummary}
 */
final readonly class CardPayload
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardLinkRepository $cardLinks,
        private CardRepository $cards,
        private CardPullRequestStates $pullRequestStates,
        private CardPauseRepository $cardPauses,
        private CardTypeCatalog $catalog,
        private CardAgentReviews $agentReviews,
        private CardMarkerText $markerText,
        private CardArtifactRepository $cardArtifacts,
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
        private CardValueReader $cardValues,
    ) {
    }

    /**
     * One card whose links, related cards and children the caller already
     * holds, from {@see \App\Module\Board\Command\ShowCardHandler}.
     *
     * @return CardSummary
     */
    public function forCard(CardView $view): array
    {
        $card = $view->card;
        $links = $view->siteReviewLinks;
        $sources = new CardSources(
            comments: static fn (): array => $links,
            related: static fn (): array => $view->relatedCards,
            children: static fn (): array => $view->children,
            states: static fn (): PullRequestStates => $view->pullRequestStates,
            reviews: fn (): array => $this->agentReviews->forCards([$card]),
            pause: fn (): ?CardPause => null === $card->id ? null : $this->cardPauses->findActiveForCard($card),
            markers: static fn (): StoredCardMarkers => $view->markers,
            artifacts: fn (): array => $this->cardArtifacts->findWithLatestVersionForCards([$card])[(string) $card->id] ?? [],
            sizes: fn (): array => $this->siteReviewScreenshots->findSizesByComments(self::commentsOf($links)),
            values: fn (): array => $this->cardValues->forCard($card),
            loadPullRequests: static function (): void {},
            loadDocuments: static function (): void {},
            loadParents: static function (): void {},
        );

        /** @var CardSummary $row */
        $row = $this->row($card, $sources, null, false);

        return $row;
    }

    /**
     * Many cards in one read, so a board-sized list costs one comment query,
     * one card link query, one children query, one parent query, one pause
     * query, one document link query, one pull request link query, one value
     * query and one query for each map of the pull request states rather than
     * one of each per card.
     *
     * @param list<Card> $cards
     *
     * @return list<CardSummary>
     */
    public function forCards(array $cards): array
    {
        $sources = $this->batchSources($cards);

        /** @var list<CardSummary> $rows */
        $rows = array_map(fn (Card $card): array => $this->row($card, $sources, null, false), $cards);

        return $rows;
    }

    /**
     * The rows of a public list, shaped by the `fields` of the request.
     *
     * No `fields` gives the light row: every plain field, no body, and a ref for each
     * pull request and each feedback item. `all` gives the whole card. Named fields
     * give those keys alone, each one whole. A field reads its data on first use,
     * so a row that never names the pull requests runs no pull request query.
     *
     * @param list<Card> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function forFields(array $cards, FieldSelection $fields): array
    {
        $sources = $this->batchSources($cards);
        $only = $fields->isDefault() ? null : $fields;

        return array_map(fn (Card $card): array => $this->row($card, $sources, $only, $fields->isDefault()), $cards);
    }

    /** @param list<Card> $cards */
    private function batchSources(array $cards): CardSources
    {
        $comments = self::once(fn (): array => $this->cardSiteReviewComments->findForCards($cards));
        $links = self::once(fn (): array => $this->cardLinks->findForCards($cards));
        $children = self::once(function () use ($cards): array {
            $epics = array_values(array_filter($cards, fn (Card $card): bool => $this->catalog->forProject($card->project->requireId())->get($card->type)->children));

            return [] === $epics ? [] : $this->cards->findChildrenOfCards($epics);
        });
        $pauses = self::once(fn (): array => $this->cardPauses->findActiveForCardIds(array_values(array_filter(array_map(static fn (Card $card): ?Uuid => $card->id, $cards)))));
        $artifacts = self::once(fn (): array => $this->cardArtifacts->findWithLatestVersionForCards($cards));
        $values = self::once(fn (): array => $this->cardValues->forCards($cards));
        $loadPullRequests = self::once(function () use ($cards): void {
            $this->cards->loadPullRequestsOf($cards);
        });
        // Both read the pull requests of every card, so the links load first.
        $states = self::once(function () use ($cards, $loadPullRequests): PullRequestStates {
            $loadPullRequests();

            return $this->pullRequestStates->forCards($cards);
        });
        $reviews = self::once(function () use ($cards, $loadPullRequests): array {
            $loadPullRequests();

            return $this->agentReviews->forCards($cards);
        });

        return new CardSources(
            comments: static fn (Card $card): array => $comments()[(string) $card->id] ?? [],
            related: static fn (Card $card): array => array_map(
                static fn (CardLink $link): RelatedCard => new RelatedCard($link->otherThan($card), $link->kindFor($card)),
                $links()[(string) $card->id] ?? [],
            ),
            children: static fn (Card $card): array => $children()[(string) $card->id] ?? [],
            states: $states,
            reviews: $reviews,
            pause: static fn (Card $card): ?CardPause => $pauses()[(string) $card->id] ?? null,
            markers: static fn (Card $card): StoredCardMarkers => StoredCardMarkers::of($card),
            artifacts: static fn (Card $card): array => $artifacts()[(string) $card->id] ?? [],
            sizes: self::once(fn (): array => $this->siteReviewScreenshots->findSizesByComments(self::commentsOf(array_merge(...array_values($comments()))))),
            values: static fn (Card $card): array => $values()[(string) $card->id] ?? [],
            loadPullRequests: $loadPullRequests,
            loadDocuments: self::once(function () use ($cards): void {
                $this->cards->loadDocumentsOf($cards);
            }),
            loadParents: self::once(function () use ($cards): void {
                $this->cards->loadParentsOf($cards);
            }),
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $load
     *
     * @return \Closure(): T
     */
    private static function once(\Closure $load): \Closure
    {
        $loaded = false;
        $value = null;

        return static function () use ($load, &$loaded, &$value) {
            if (!$loaded) {
                $value = $load();
                $loaded = true;
            }

            return $value;
        };
    }

    /**
     * The lean shape a board listing reads in, with no body and none of the
     * link sets.
     *
     * It runs no comment or card link query at all, which is the point: the
     * full shape costs both whether or not the caller reads them back.
     *
     * @param list<Card> $cards
     *
     * @return list<CardListSummary>
     */
    public function forCardList(array $cards): array
    {
        return array_map(
            fn (Card $card): array => [
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'title' => $card->title,
                'type' => $card->type,
                'status' => $card->column->slug,
                'reporter' => $card->reporter->value,
                // The id alone, which a parent proxy holds without a query.
                'parentCardId' => null === $card->parent ? null : (string) $card->parent->id,
                'updatedAt' => $card->updatedAt->format(\DATE_ATOM),
                'state' => null === ($state = $this->stateOf($card)) ? null : [
                    'kind' => $state->kind->value,
                    'code' => $state->key,
                    'since' => $state->since->format(\DATE_ATOM),
                ],
            ],
            $cards,
        );
    }

    /** The marker that gives the card its mark, or null for a card with none, a card in a terminal column and a card with only chips. */
    private function stateOf(Card $card): ?StoredMarker
    {
        return $card->column->terminal ? null : StoredCardMarkers::of($card)->state();
    }

    /**
     * @param ?FieldSelection $only  the fields to read, or null for every field
     * @param bool            $light true for the row of a list, which leaves out the body and holds a ref for each pull request and each feedback item
     *
     * @return array<string, mixed>
     */
    private function row(Card $card, CardSources $sources, ?FieldSelection $only, bool $light): array
    {
        $row = [];
        foreach ($this->fields($card, $sources, $light) as $name => $read) {
            if (null === $only || $only->has($name)) {
                $row[$name] = $read();
            }
        }

        return $row;
    }

    /**
     * One reader for each field, in the order of the card. A reader reads its data when it runs.
     *
     * @return array<string, \Closure(): mixed>
     */
    private function fields(Card $card, CardSources $sources, bool $light): array
    {
        $fields = [
            'cardId' => static fn (): string => (string) $card->id,
            // The short per-project label a person says out loud. Not the id.
            'number' => static fn (): int => $card->number,
            'title' => static fn (): string => $card->title,
            'body' => static fn (): string => $card->body,
            'type' => static fn (): string => $card->type,
            'status' => static fn (): string => $card->column->slug,
            'reporter' => static fn (): string => $card->reporter->value,
            'position' => static fn (): int => $card->position,
            'completedAt' => static fn (): ?string => $card->completedAt?->format(\DATE_ATOM),
            'createdAt' => static fn (): string => $card->createdAt->format(\DATE_ATOM),
            'updatedAt' => static fn (): string => $card->updatedAt->format(\DATE_ATOM),
            'pullRequests' => function () use ($card, $sources, $light): array {
                ($sources->loadPullRequests)();
                if ($light) {
                    return array_map(
                        static fn (CardPullRequest $link): array => [
                            'pullRequestId' => (string) $link->id,
                            'url' => $link->url,
                            'forge' => $link->forge->value,
                            'repository' => $link->repository,
                            'number' => $link->number,
                        ],
                        array_values($card->pullRequests->toArray()),
                    );
                }
                $states = ($sources->states)();
                $reviews = ($sources->reviews)();

                return array_map(
                    static fn (CardPullRequest $link): array => [
                        'pullRequestId' => (string) $link->id,
                        'url' => $link->url,
                        'forge' => $link->forge->value,
                        'repository' => $link->repository,
                        'number' => $link->number,
                        'state' => self::pullRequestState($states->of($link)),
                        'agentReview' => $reviews[(string) $link->id] ?? null,
                    ],
                    array_values($card->pullRequests->toArray()),
                );
            },
            // The documents this card's work is written up in. Read only here:
            // a document is written and revised through its own tools.
            'documents' => function () use ($card, $sources): array {
                ($sources->loadDocuments)();

                return array_map(
                    static fn (CardDocument $link): array => [
                        'documentId' => (string) $link->document->id,
                        'title' => $link->document->title,
                        'status' => $link->document->status->value,
                    ],
                    array_values($card->documents->toArray()),
                );
            },
            // The prototypes stored for this card. Read only: an agent stores one
            // through the CLI, and links it with artifactIds.
            'artifacts' => static fn (): array => array_map(
                static fn (array $row): array => [
                    'artifactId' => (string) $row['link']->artifact->id,
                    'name' => $row['link']->artifact->name,
                    'versionNumber' => $row['versionNumber'],
                    'archived' => null !== $row['link']->artifact->archivedAt,
                    'path' => \sprintf('/projects/%s/artifacts/%s', $row['link']->artifact->project->id, $row['link']->artifact->id),
                ],
                ($sources->artifacts)($card),
            ),
            // Feedback a reviewer left on this card. Read only: an agent marks
            // one addressed through feedback_mark_addressed.
            'siteReviewComments' => static function () use ($card, $sources, $light): array {
                $links = ($sources->comments)($card);
                if ($light) {
                    return array_map(
                        static fn (CardSiteReviewComment $link): array => [
                            'id' => (string) $link->comment->id,
                            'status' => $link->comment->status->value,
                            'url' => $link->comment->url,
                        ],
                        $links,
                    );
                }
                $sizes = ($sources->sizes)();

                return array_map(
                    static fn (CardSiteReviewComment $link): array => self::feedback($link->comment, $sizes[(string) $link->comment->id] ?? null),
                    $links,
                );
            },
            // The kind reads from this card's side: the target of a blocks
            // link reads blocked-by, and the target of a has-follow-up link reads follow-up-of.
            'relatedCards' => static fn (): array => array_map(
                static fn (RelatedCard $related): array => [
                    'cardId' => (string) $related->card->id,
                    'number' => $related->card->number,
                    'title' => $related->card->title,
                    'status' => $related->card->column->slug,
                    'kind' => $related->kind->value,
                ],
                ($sources->related)($card),
            ),
            'parent' => static function () use ($card, $sources): ?array {
                ($sources->loadParents)();

                return null === $card->parent ? null : self::reference($card->parent);
            },
            'laneEnabled' => static fn (): bool => $card->laneEnabled,
            'requiresHumanReview' => static fn (): bool => $card->requiresHumanReview,
            'values' => static fn (): array => array_map(
                static fn (CardValueView $value): array => [
                    'key' => $value->key,
                    'type' => $value->type->value,
                    'visibility' => $value->visibility->value,
                    'value' => $value->value,
                    'writer' => null === $value->writerKind ? null : ['kind' => $value->writerKind->value, 'name' => $value->writerUser?->fullName],
                    'updatedAt' => $value->updatedAt?->format(\DATE_ATOM),
                ],
                ($sources->values)($card),
            ),
            'children' => static fn (): array => array_map(self::reference(...), ($sources->children)($card)),
            'progress' => function () use ($card, $sources): ?array {
                if (!$this->catalog->forProject($card->project->requireId())->get($card->type)->children) {
                    return null;
                }
                $children = ($sources->children)($card);

                return ['done' => \count(array_filter($children, static fn (Card $child): bool => $child->column->terminal)), 'total' => \count($children)];
            },
            'pause' => static function () use ($card, $sources): ?array {
                $pause = ($sources->pause)($card);

                return null === $pause ? null : [
                    'pauseId' => (string) $pause->id,
                    'kind' => $pause->kind->value,
                    'reason' => $pause->reason,
                    'ruleId' => $pause->ruleId,
                    'since' => $pause->createdAt->format(\DATE_ATOM),
                ];
            },
            'state' => function () use ($card, $sources): ?array {
                $state = $card->column->terminal ? null : ($sources->markers)($card)->state();
                if (null === $state) {
                    return null;
                }

                return [
                    'kind' => $state->kind->value,
                    'code' => $state->key,
                    'since' => $state->since->format(\DATE_ATOM),
                    'reason' => $this->markerText->label($state),
                    'others' => array_map(
                        fn (StoredMarker $other): array => [
                            'kind' => $other->kind->value,
                            'code' => $other->key,
                            'reason' => $this->markerText->label($other),
                            'since' => $other->since->format(\DATE_ATOM),
                        ],
                        array_values(array_filter(($sources->markers)($card)->others(), static fn (StoredMarker $other): bool => MarkerKind::Chip !== $other->kind)),
                    ),
                ];
            },
        ];
        if ($light) {
            unset($fields['body']);
        }

        return $fields;
    }

    /**
     * One feedback item, in the shape card_get and feedback_list share.
     *
     * @param ScreenshotSize|null $screenshot the size of the note's screenshot, null for a note with none
     *
     * @return FeedbackSummary
     */
    public static function feedback(SiteReviewComment $comment, ?array $screenshot): array
    {
        return [
            'id' => (string) $comment->id,
            'url' => $comment->url,
            'surface' => $comment->surface->value,
            'native' => $comment->native,
            'screenshot' => $screenshot,
            'anchors' => array_values(array_map(
                static fn (SiteReviewCommentAnchor $anchor): array => [
                    'selector' => $anchor->selector,
                    'text' => $anchor->text,
                    'quote' => $anchor->quote,
                    'quotePrefix' => $anchor->quotePrefix,
                    'quoteSuffix' => $anchor->quoteSuffix,
                ],
                $comment->anchors->toArray(),
            )),
            'body' => $comment->body,
            // The strokes are vector points over a live page, which an agent
            // cannot render. The flag says to ask the reviewer rather than guess.
            'hasDrawing' => null !== $comment->strokes && [] !== $comment->strokes,
            'status' => $comment->status->value,
            // What the page said it was when the note was made. Only a preview
            // deployment sets it, so null is the common answer.
            'context' => $comment->context,
            'createdAt' => $comment->createdAt->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<CardSiteReviewComment> $links
     *
     * @return list<SiteReviewComment>
     */
    private static function commentsOf(array $links): array
    {
        return array_map(static fn (CardSiteReviewComment $link): SiteReviewComment => $link->comment, $links);
    }

    /** @return ?PullRequestStateSummary */
    private static function pullRequestState(?PullRequestStateView $state): ?array
    {
        $refreshedAt = $state?->refreshedAt;
        if (null === $state || null === $refreshedAt) {
            return null;
        }

        return [
            'state' => $state->state->value,
            'draft' => $state->draft,
            'checks' => $state->checks->value,
            'failedChecks' => $state->failedChecks,
            'mergeability' => $state->mergeability->value,
            'review' => $state->review->value,
            'readyToMerge' => $state->readyToMerge,
            'refreshedAt' => $refreshedAt->format(\DATE_ATOM),
        ];
    }

    /** @return CardRefSummary */
    private static function reference(Card $card): array
    {
        return [
            'cardId' => (string) $card->id,
            'number' => $card->number,
            'title' => $card->title,
            'status' => $card->column->slug,
        ];
    }
}
