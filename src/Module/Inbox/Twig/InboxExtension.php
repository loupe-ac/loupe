<?php

declare(strict_types=1);

namespace App\Module\Inbox\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ShowInboxOpenTotalCommand;
use App\Module\Inbox\Command\ShowInboxOpenTotalHandler;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Form\CancelInboxActionFormType;
use App\Module\Inbox\Form\RunInboxActionFormType;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxRepositoryMisfitRepository;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\InboxOutcome;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Inbox\Service\InboxReviewTarget;
use App\Module\Inbox\Service\InboxThreadActionBuilder;
use App\Module\Inbox\Service\InboxThreadBuilder;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\View\CardWaitLine;
use App\Module\Inbox\View\InboxThreadActions;
use App\Module\Inbox\View\InboxThreadView;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Review;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\MarkdownRenderer;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The inbox page renders a reply form and a cancel form per item, so they are built here.
 * Each carries the item's own name, and a refused form comes back in place of
 * a fresh one only when the name matches.
 */
final class InboxExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly MarkdownRenderer $markdown,
        private readonly InboxItemRepository $inboxItems,
        private readonly UserTopicBuilder $topics,
        private readonly ProjectTopicBuilder $projectTopics,
        private readonly InboxReviewLookup $inboxReviews,
        private readonly InboxReviewTarget $reviewTarget,
        private readonly ReviewRepository $reviews,
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CardWaitText $cardWaitText,
        private readonly InboxThreadActionBuilder $threadActions,
        private readonly InboxActionRepository $inboxActions,
        private readonly ShowInboxOpenTotalHandler $openTotal,
        private readonly InboxRepositoryMisfitRepository $inboxRepositoryMisfits,
        private readonly InboxOutcome $outcome,
        private readonly InboxThreadBuilder $threads,
        private readonly ItemActions $itemActions,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_reply_form', $this->replyForm(...)),
            new TwigFunction('inbox_action_cancel_form', $this->actionCancelForm(...)),
            new TwigFunction('inbox_actions', $this->actions(...)),
            new TwigFunction('inbox_thread', $this->thread(...)),
            new TwigFunction('inbox_review', $this->review(...)),
            new TwigFunction('inbox_outcome', $this->outcome->forItem(...)),
            new TwigFunction('inbox_review_target', $this->reviewTargetLine(...)),
            new TwigFunction('inbox_review_targets', $this->reviewTargets(...)),
            new TwigFunction('inbox_repository_misfit', $this->repositoryMisfit(...)),
            new TwigFunction('inbox_wait_line', $this->waitLine(...)),
            new TwigFunction('inbox_review_withdrawal', $this->reviewWithdrawal(...)),
            new TwigFunction('inbox_review_version', $this->reviewVersion(...)),
            new TwigFunction('inbox_refusals', $this->refusals(...)),
            new TwigFunction('project_inbox_open_count', $this->openCount(...)),
            new TwigFunction('inbox_open_total', $this->openTotal(...)),
            new TwigFunction('inbox_topic', $this->topic(...)),
            new TwigFunction('inbox_page_topic', $this->pageTopic(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            // No heading ids: an item shows beside a document whose own headings carry them.
            new TwigFilter('inbox_markdown', $this->markdown->renderWithoutHeadingIds(...), ['is_safe' => ['html']]),
            new TwigFilter('inbox_plain_text', $this->plainText(...)),
            new TwigFilter('inbox_age', self::age(...)),
        ];
    }

    /** The short age of a list row, such as "2h" or "3w". The app ships English only. */
    public static function age(\DateTimeImmutable $when, ?\DateTimeImmutable $now = null): string
    {
        $seconds = max(0, ($now ?? new \DateTimeImmutable())->getTimestamp() - $when->getTimestamp());

        return match (true) {
            $seconds < 60 => 'now',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            $seconds < 604800 => intdiv($seconds, 86400).'d',
            $seconds < 2592000 => intdiv($seconds, 604800).'w',
            $seconds < 31557600 => intdiv($seconds, 2592000).'mo',
            default => intdiv($seconds, 31557600).'y',
        };
    }

    /**
     * One run of plain text from a body written in Markdown, for a list row.
     *
     * Not marked safe, so Twig escapes it as the user content it is. The
     * entities the renderer wrote are decoded first, because Twig escapes
     * again and an `&` would otherwise reach the page as `&amp;amp;`.
     */
    public function plainText(?string $markdown): string
    {
        if (null === $markdown || '' === trim($markdown)) {
            return '';
        }

        $text = html_entity_decode(
            strip_tags($this->markdown->renderWithoutHeadingIds($markdown)),
            ENT_QUOTES | ENT_HTML5,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function review(InboxItem $item): ?InboxReview
    {
        return $this->inboxReviews->forItem($item);
    }

    /**
     * The line that names the document or pull request of each review item of an ask, wherever it sits in the ask.
     * Null when the ask holds no review item.
     *
     * @param iterable<InboxItem> $items
     */
    public function reviewTargetLine(iterable $items): ?string
    {
        $labels = [];
        foreach ($items as $item) {
            $review = InboxItemKind::Review === $item->kind ? $this->inboxReviews->forItem($item) : null;
            if (null !== $review) {
                $labels[] = $this->reviewTarget->label($review);
            }
        }

        return [] === $labels ? null : implode(' · ', array_unique($labels));
    }

    /**
     * The review behind each review item of an ask, which the byline names with a link.
     *
     * @param iterable<InboxItem> $items
     *
     * @return list<InboxReview>
     */
    public function reviewTargets(iterable $items): array
    {
        $reviews = [];
        foreach ($items as $item) {
            $review = InboxItemKind::Review === $item->kind ? $this->inboxReviews->forItem($item) : null;
            if (null !== $review) {
                $reviews[] = $review;
            }
        }

        return $reviews;
    }

    /** The misfit behind an open notice of a blocking repository, or null for any other item. */
    public function repositoryMisfit(InboxItem $item): ?InboxRepositoryMisfit
    {
        if (InboxItemKind::Notice !== $item->kind || InboxItemState::Open !== $item->state) {
            return null;
        }

        return $this->inboxRepositoryMisfits->findOneForItem($item);
    }

    public function waitLine(InboxCardWait $wait, bool $documentLinked): CardWaitLine
    {
        return $this->cardWaitText->line($wait, $documentLinked);
    }

    public function reviewWithdrawal(InboxReview $review): ?Review
    {
        return null === $review->documentReview ? null : $this->reviews->findWithdrawalOf($review->documentReview);
    }

    /** The version a document review names: the one reviewed, or the latest until then. */
    public function reviewVersion(InboxReview $review): ?int
    {
        if (null !== $review->reviewedVersionNumber) {
            return $review->reviewedVersionNumber;
        }

        return null === $review->document ? null : $this->documentVersions->findLatest($review->document)->versionNumber;
    }

    /**
     * The one form of the reply card: the note, the answer or the verdict, and the action a button names. A review form carries
     * the version or the address the owner reads, so a review of something that changed since is refused.
     * A refused form of this item comes back in place of a fresh one, under whichever of its two names it was posted.
     */
    public function replyForm(InboxItem $item, ?FormView $refused = null): FormView
    {
        $name = $item->isAutomatic() ? RunInboxActionFormType::nameFor($item) : RunInboxActionFormType::responseNameFor($item);
        if (null !== $refused && \in_array($refused->vars['name'], [RunInboxActionFormType::nameFor($item), RunInboxActionFormType::responseNameFor($item)], true)) {
            return $refused;
        }

        $data = new RunInboxActionRequest(sessionPrompt: $this->itemActions->sessionPromptFor($item));
        $review = InboxItemKind::Review === $item->kind && !$item->isAutomatic() ? $this->inboxReviews->forItem($item) : null;
        if (null !== $review?->document) {
            $version = $this->documentVersions->findLatest($review->document);
            $data->versionNumber = $version->versionNumber;
            $data->expectedReviewId = $this->reviews->findNewestByVersion($version)?->id?->toRfc4122();
        } elseif (null !== $review?->pullRequest) {
            $data->expectedUrl = $review->pullRequest->url;
        }

        return $this->formFactory->createNamed($name, RunInboxActionFormType::class, $data)->createView();
    }

    public function actionCancelForm(InboxAction $action, ?FormView $refused = null): FormView
    {
        $name = CancelInboxActionFormType::nameFor($action);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory->createNamed($name, CancelInboxActionFormType::class)->createView();
    }

    /** The split button of the item. The host card is the one whose page shows the item, so it gets no link back. */
    public function actions(InboxItem $item, ?string $hostCardId = null): InboxThreadActions
    {
        return $this->threadActions->build($item, $hostCardId);
    }

    /** The thread of the item: its request, the actions the owner ran, the response or closing message, and the reply card. */
    public function thread(InboxItem $item, ?string $hostCardId = null): InboxThreadView
    {
        return $this->threads->build($item, $hostCardId);
    }

    /**
     * The messages of a refused form of this item, root and fields alike.
     *
     * A refusal that makes an item final leaves no form on the page to carry
     * its errors, so the item shows them on its own.
     *
     * @return list<string>
     */
    public function refusals(InboxItem $item, ?FormView $refused = null): array
    {
        $names = [RunInboxActionFormType::responseNameFor($item), RunInboxActionFormType::nameFor($item)];
        if (null !== $refused && str_starts_with((string) $refused->vars['name'], 'inbox_action_cancel_')) {
            $names = [...$names, ...array_map(CancelInboxActionFormType::nameFor(...), $this->inboxActions->findByItem($item))];
        }
        if (null === $refused || !\in_array($refused->vars['name'], $names, true)) {
            return [];
        }

        $messages = [];
        foreach ([$refused, ...$refused->children] as $view) {
            foreach ($view->vars['errors'] ?? [] as $error) {
                if ($error instanceof FormError) {
                    $messages[] = $error->getMessage();
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /** Feeds the sidebar pill, which shows only while the project has open items. */
    public function openCount(Project $project): int
    {
        return $this->inboxItems->countOpenByProject($project);
    }

    /** Feeds the tab title: the open items over every project of the user, or zero with nobody signed in. */
    public function openTotal(?User $user): int
    {
        return null === $user ? 0 : ($this->openTotal)(new ShowInboxOpenTotalCommand($user));
    }

    /** The topic the pill listens on for the open counts of every project of the user. */
    public function topic(User $user): string
    {
        return $this->topics->forInbox($user->id ?? throw new \LogicException('User has no id.'));
    }

    /** The topic the inbox pages of a project listen on for changes of the asks. */
    public function pageTopic(Project $project): string
    {
        return $this->projectTopics->forInbox($project->id ?? throw new \LogicException('Project has no id.'));
    }
}
