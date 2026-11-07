<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\FeedbackCardTitle;
use App\Module\Board\Service\WidgetSignIn;
use App\Module\Board\Service\WidgetStartOptions;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Service\DecodedScreenshot;
use App\Module\SiteReview\Service\ScreenshotDecoder;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Saves a site-review note together with the card it belongs to, creating
 * that card when the note asks for one. The card, the comment and the link
 * commit in one transaction, so neither exists without the other.
 */
final readonly class AddFeedbackHandler
{
    public const string TARGET_NOT_FOUND = 'target_not_found';
    public const string TARGET_CLOSED = 'target_closed';

    public function __construct(
        private CreateCardHandler $createCard,
        private AddCommentHandler $addComment,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private CardTypeCatalog $catalog,
        private ScreenshotDecoder $screenshotDecoder,
        private BoardColumnRepository $boardColumns,
        private WidgetStartOptions $startOptions,
        private WidgetSignIn $signIn,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(AddFeedbackCommand $command): AddFeedbackResult
    {
        if (null !== $command->deliveryId && !Uuid::isValid($command->deliveryId)) {
            throw new DomainErrors(['deliveryId' => 'delivery_invalid']);
        }

        // Checked before any write, so a refused image leaves no card behind.
        $screenshot = null === $command->screenshot ? null : $this->screenshotDecoder->decode($command->screenshot);

        $created = false;
        $startRefused = null;
        // The sub-handlers open nested transactions, which run as savepoints of
        // this one. Every refusal leaves this closure as a value, because a throw
        // closes the EntityManager, and none can happen once a card exists.
        $link = $this->em->wrapInTransaction(function () use ($command, $screenshot, &$created, &$startRefused): CardSiteReviewComment|DomainErrors {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $retried = null === $command->deliveryId ? null : $this->siteReviewComments->findOneBy([
                'project' => $command->project,
                'deliveryId' => Uuid::fromString($command->deliveryId),
            ]);
            if (null !== $retried) {
                try {
                    // Called for its payload check: a changed body or target under
                    // a known delivery id is a conflict, not a retry.
                    ($this->addComment)($this->addCommentCommand($command, $screenshot));
                } catch (DomainErrors $conflict) {
                    return $conflict;
                }

                // A comment saved through the old path has no card to return.
                return $this->cardSiteReviewComments->findOneBy(['comment' => $retried])
                    ?? new DomainErrors(['deliveryId' => 'delivery_conflict']);
            }

            $card = $this->targetCard($command);
            if ($card instanceof DomainErrors) {
                return $card;
            }
            $createdCard = null === $card;
            $column = null;
            if ($createdCard && null !== $command->startColumnId) {
                $column = $this->startColumn($command);
                $startRefused = null === $column ? $this->refusedColumnName($command) : null;
            }
            $card ??= ($this->createCard)(new CreateCardCommand(
                project: $command->project,
                title: FeedbackCardTitle::of($command->body),
                body: Card::normalText($command->body),
                type: $this->catalog->forProject($command->project->requireId())->defaultKey,
                column: $column,
                reporter: Actor::Reviewer,
                source: new CardSource(CardSourceKind::Widget),
            ));

            $comment = ($this->addComment)($this->addCommentCommand($command, $screenshot));
            $link = new CardSiteReviewComment($card, $comment, $createdCard, $createdCard ? $card->title : null, $createdCard ? $card->body : null);
            $this->em->persist($link);
            $created = true;

            return $link;
        });

        if ($link instanceof DomainErrors) {
            throw $link;
        }
        if (!$created) {
            return new AddFeedbackResult($link, null);
        }

        $this->auditor->record(
            'board.feedback_added',
            AuditOutcome::Success,
            [
                'cardId' => (string) $link->card->id,
                'commentId' => (string) $link->comment->id,
                'projectId' => (string) $command->project->id,
                'createdCard' => $link->createdCard,
            ],
            new AuditSubject('card', (string) $link->card->id),
        );

        // A created card announced itself, and its face already counts the note.
        if (!$link->createdCard) {
            $this->events->dispatch(new CardChanged(
                $command->project->id ?? throw new \LogicException('Project has no id.'),
                $link->card->id ?? throw new \LogicException('Card has no id.'),
                CardChanged::UPDATED,
                false,
            ));
        }

        return new AddFeedbackResult($link, $startRefused);
    }

    /** The open card the note names, null for a card still to create, or the refusal. */
    private function targetCard(AddFeedbackCommand $command): Card|DomainErrors|null
    {
        $projectId = (string) $command->project->id;

        if (null !== $command->cardId) {
            $card = $this->cards->findOneByIdAndProjectId($command->cardId, $projectId);
            if (null === $card) {
                return new DomainErrors(['target' => self::TARGET_NOT_FOUND]);
            }

            return $card->column->terminal ? new DomainErrors(['target' => self::TARGET_CLOSED]) : $card;
        }

        return null;
    }

    /**
     * The column the note asked for, when the move guard still allows it from
     * Backlog. Read under the project lock, so a column deleted or a rule
     * changed since the boot load decides here. A preview token starts nothing.
     */
    private function startColumn(AddFeedbackCommand $command): ?BoardColumn
    {
        if ($this->signIn->isPreviewToken() || !Uuid::isValid((string) $command->startColumnId)) {
            return null;
        }
        $columns = $this->boardColumns->findForProjectFresh($command->project);
        $column = array_find($columns, static fn (BoardColumn $candidate): bool => strtolower((string) $command->startColumnId) === (string) $candidate->id);
        if (null === $column) {
            return null;
        }
        if ($column->backlog) {
            return $column;
        }

        return \in_array($column, $this->startOptions->allowedAmong($command->project, $columns), true) ? $column : null;
    }

    /** The name of the refused column for the answer, or an empty string when the id names no column of the project. */
    private function refusedColumnName(AddFeedbackCommand $command): string
    {
        $column = Uuid::isValid((string) $command->startColumnId)
            ? $this->boardColumns->findOneByIdAndProjectId((string) $command->startColumnId, (string) $command->project->id)
            : null;

        return null === $column ? '' : $this->translator->trans($column->label);
    }

    private function addCommentCommand(AddFeedbackCommand $command, ?DecodedScreenshot $screenshot): AddCommentCommand
    {
        return new AddCommentCommand(
            project: $command->project,
            body: $command->body,
            url: $command->url,
            anchors: $command->anchors,
            strokes: $command->strokes,
            context: $command->context,
            deliveryId: $command->deliveryId,
            deliveryScope: self::deliveryScope($command),
            surface: $command->surface,
            native: $command->native,
            screenshot: $screenshot,
        );
    }

    /**
     * The target as the request named it. It joins the delivery hash, so a
     * retry to another target conflicts whatever happened to the card since.
     */
    private static function deliveryScope(AddFeedbackCommand $command): string
    {
        if (null !== $command->cardId) {
            return 'card:'.strtolower(trim($command->cardId));
        }

        return 'new';
    }
}
