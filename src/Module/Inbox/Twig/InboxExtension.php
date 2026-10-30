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
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Form\CancelInboxActionFormType;
use App\Module\Inbox\Form\RunInboxActionFormType;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxRepositoryMisfitRepository;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\InboxActionStatuses;
use App\Module\Inbox\Service\InboxOutcome;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Inbox\Service\InboxReviewTarget;
use App\Module\Inbox\Service\InboxThreadActionBuilder;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\View\CardWaitLine;
use App\Module\Inbox\View\InboxActionStatus;
use App\Module\Inbox\View\InboxThreadActions;
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
 * The inbox page renders up to three forms per item, so they are built here.
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
        private readonly InboxCardWatchRepository $inboxCardWatches,
        private readonly CardWaitText $cardWaitText,
        private readonly InboxThreadActionBuilder $threadActions,
        private readonly InboxActionStatuses $actionStatuses,
        private readonly InboxActionRepository $inboxActions,
        private readonly ShowInboxOpenTotalHandler $openTotal,
        private readonly InboxRepositoryMisfitRepository $inboxRepositoryMisfits,
        private readonly InboxOutcome $outcome,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_response_form', $this->responseForm(...)),
            new TwigFunction('inbox_action_form', $this->actionForm(...)),
            new TwigFunction('inbox_action_cancel_form', $this->actionCancelForm(...)),
            new TwigFunction('inbox_actions', $this->actions(...)),
            new TwigFunction('inbox_action_statuses', $this->actionStatusLines(...)),
            new TwigFunction('inbox_review', $this->review(...)),
            new TwigFunction('inbox_outcome', $this->outcome->forItem(...)),
            new TwigFunction('inbox_review_target', $this->reviewTargetLine(...)),
            new TwigFunction('inbox_card_watch', $this->cardWatch(...)),
            new TwigFunction('inbox_repository_misfit', $this->repositoryMisfit(...)),
            new TwigFunction('inbox_page_waits', $this->cardWaitText->pageWaits(...)),
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

    /** The card and the waits behind a wait item. */
    public function cardWatch(InboxItem $item): ?InboxCardWatch
    {
        return $this->inboxCardWatches->findOneForItem($item);
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
     * The form of the response of the owner: an answer, a verdict, a done or a dismissal. A review form carries
     * the version or the address the owner reads, so a review of something that changed since is refused.
     */
    public function responseForm(InboxItem $item, ?FormView $refused = null): FormView
    {
        $name = RunInboxActionFormType::responseNameFor($item);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        $data = new RunInboxActionRequest();
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

    public function actionForm(InboxItem $item, ?FormView $refused = null): FormView
    {
        $name = RunInboxActionFormType::nameFor($item);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory->createNamed($name, RunInboxActionFormType::class, new RunInboxActionRequest(sessionPrompt: ItemActions::suggestedSessionPrompt($item)))->createView();
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

    /** @return list<InboxActionStatus> */
    public function actionStatusLines(InboxItem $item): array
    {
        return $this->actionStatuses->forItems([$item])[(string) $item->id] ?? [];
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
