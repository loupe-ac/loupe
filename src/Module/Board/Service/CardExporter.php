<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The board cards in the account data export.
 *
 * It reads no feature flag. An export states what the account holds, and cards
 * written while the board was on stay the account's data after it goes off.
 */
final readonly class CardExporter implements UserDataExporterInterface
{
    public function __construct(
        private CardRepository $cards,
        private CardLinkRepository $cardLinks,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardEventRepository $cardEvents,
        private CardValueRepository $cardValues,
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'cards.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $cards = $this->cards->findByOwner($user);
        $links = $this->cardLinks->findForCards($cards);

        foreach ($cards as $card) {
            $feedback = $this->cardSiteReviewComments->findForCard($card);
            $screenshots = $this->siteReviewScreenshots->findByComments(array_map(
                static fn (CardSiteReviewComment $link): SiteReviewComment => $link->comment,
                $feedback,
            ));

            yield [
                'id' => (string) $card->id,
                'project' => $card->project->name,
                'title' => $card->title,
                'body' => $card->body,
                'status' => $card->column->slug,
                'column' => $this->translator->trans($card->column->label),
                'type' => $card->type,
                'reporter' => $card->reporter->value,
                'source' => $card->source->kind->value,
                'sourceRunId' => $card->source->runId?->toRfc4122(),
                'sourceRunCardId' => $card->source->runCardId?->toRfc4122(),
                'position' => $card->position,
                'completedAt' => $card->completedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $card->createdAt->format(\DateTimeInterface::ATOM),
                'updatedAt' => $card->updatedAt->format(\DateTimeInterface::ATOM),
                'pullRequests' => array_values(array_map(
                    static fn (CardPullRequest $link): array => [
                        'url' => $link->url,
                        'forge' => $link->forge->value,
                        'repository' => $link->repository,
                        'number' => $link->number,
                        'addedAt' => $link->addedAt->format(\DateTimeInterface::ATOM),
                    ],
                    $card->pullRequests->toArray(),
                )),
                // Ids alone: a document's own text belongs to the Review exporter.
                'documents' => array_values(array_map(
                    static fn (CardDocument $link): string => (string) $link->document->id,
                    $card->documents->toArray(),
                )),
                // In full, because feedback has no file of its own.
                'feedback' => array_map(
                    fn (CardSiteReviewComment $link): array => [
                        'id' => (string) $link->comment->id,
                        'surface' => $link->comment->surface->value,
                        'url' => $link->comment->url,
                        'native' => $link->comment->native,
                        // An image is binary, so it travels as base64, as an artifact file does.
                        'screenshot' => self::screenshotOf($screenshots[(string) $link->comment->id] ?? null),
                        'context' => $link->comment->context,
                        'anchors' => array_values(array_map(
                            static fn (SiteReviewCommentAnchor $anchor): array => [
                                'selector' => $anchor->selector,
                                'text' => $anchor->text,
                                'quote' => $anchor->quote,
                                'quotePrefix' => $anchor->quotePrefix,
                                'quoteSuffix' => $anchor->quoteSuffix,
                            ],
                            $link->comment->anchors->toArray(),
                        )),
                        'body' => $link->comment->body,
                        'strokes' => $link->comment->strokes ?? [],
                        'status' => $link->comment->status->value,
                        'createdAt' => $link->comment->createdAt->format(\DateTimeInterface::ATOM),
                    ],
                    $feedback,
                ),
                'relatedCards' => array_map(
                    static fn (CardLink $link): array => [
                        'cardId' => (string) $link->otherThan($card)->id,
                        'number' => $link->otherThan($card)->number,
                        'kind' => $link->kindFor($card)->value,
                    ],
                    $links[(string) $card->id] ?? [],
                ),
                'parentCardId' => null === $card->parent ? null : (string) $card->parent->id,
                'parentNumber' => $card->parent?->number,
                'laneEnabled' => $card->laneEnabled,
                // No writer user, for the reason given for the history below.
                'values' => array_map(
                    static fn (CardValue $value): array => [
                        'key' => $value->key,
                        'value' => $value->value,
                        'writerKind' => $value->writerKind->value,
                        'updatedAt' => $value->updatedAt->format(\DateTimeInterface::ATOM),
                    ],
                    $this->cardValues->findForCard($card),
                ),
                // No actor user: another account's name is not this account's data.
                'history' => array_map(
                    static fn (CardEvent $event): array => [
                        'kind' => $event->kind->value,
                        'occurredAt' => $event->occurredAt->format(\DateTimeInterface::ATOM),
                        'actorKind' => $event->actorKind->value,
                        'detail' => $event->detail,
                    ],
                    $this->cardEvents->findForCard($card),
                ),
            ];
        }
    }

    /** @return array{contentType: string, width: int, height: int, size: int, base64: string}|null */
    private static function screenshotOf(?SiteReviewScreenshot $screenshot): ?array
    {
        return null === $screenshot ? null : [
            'contentType' => $screenshot->contentType,
            'width' => $screenshot->width,
            'height' => $screenshot->height,
            'size' => $screenshot->size,
            'base64' => base64_encode($screenshot->contents()),
        ];
    }
}
