<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\InitPullRequestReaders;
use App\Module\Forge\Service\MergeApprovals;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxMergeApprovalQuestion;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps one open merge question for each repository of a project that has no valid answer to it, and closes the
 * question when `.loupe.yaml` states the setting or the repository no longer fits. It reads the case itself and
 * never trusts an event, so a late or repeated trigger changes nothing. The owner's answer keeps the row open
 * until the setting is stated, so the same case opens no second question.
 *
 * While `loupe init` runs on the project, it opens no question and closes none. A repository whose open
 * init pull request sets the merge setting is not asked, because the setting counts once that pull request merges.
 */
final readonly class MergeApprovalQuestionReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ForgeRepositoryRepository $forgeRepositories,
        private MergeApprovals $mergeApprovals,
        private InitRuns $initRuns,
        private InitPullRequestReaders $initPullRequests,
        private InboxMergeApprovalQuestionRepository $inboxMergeApprovalQuestions,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxChangedPublisher $inboxChanged,
        private InboxAvailability $inbox,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private MessageBusInterface $bus,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public function reconcile(Project $project): void
    {
        // A cheap read to skip the forge call. The hold that counts is the one read under the lock.
        $heldBefore = $this->initRuns->isOpen($project->requireId());
        $answeredByInitPullRequest = $heldBefore || !$this->inbox->isEnabled() ? [] : $this->answeredByInitPullRequest($project);

        $changed = $this->reconcileUnderLock($project, $answeredByInitPullRequest, $heldBefore);
        if (null === $changed) {
            // The run closed after the cheap read, which skipped the forge. Read it now, outside the lock.
            $changed = $this->reconcileUnderLock($project, $this->answeredByInitPullRequest($project), false);
        }

        if ($changed) {
            $this->inboxChanged->changed($project);
        }
    }

    /**
     * @param array<string, true> $answeredByInitPullRequest
     *
     * @return bool|null null when the hold ended after the caller skipped the forge read, so nothing changed
     */
    private function reconcileUnderLock(Project $project, array $answeredByInitPullRequest, bool $heldBefore): ?bool
    {
        return $this->em->wrapInTransaction(function () use ($project, $answeredByInitPullRequest, $heldBefore): ?bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            // Read under the lock of the init launch, so a launch that commits first is seen.
            $held = $this->initRuns->isOpen($project->requireId());
            if ($heldBefore && !$held && $this->inbox->isEnabled()) {
                return null;
            }

            $now = self::whole($this->clock->now());
            $open = [];
            foreach ($this->inboxMergeApprovalQuestions->findOpenForProject($project) as $question) {
                $open[$question->repositoryId->toRfc4122()] = $question;
            }

            $wanted = [];
            if ($this->inbox->isEnabled()) {
                foreach ($this->forgeRepositories->findByProject($project) as $repository) {
                    $id = ($repository->id ?? throw new \LogicException('A stored repository has an id.'))->toRfc4122();
                    if (ForgeRepositorySource::Installation === $repository->source && !isset($answeredByInitPullRequest[$id]) && $this->mergeApprovals->of($repository)->unanswered(true)) {
                        $wanted[$id] = $repository;
                    }
                }
            }

            $changed = false;
            foreach ($open as $repositoryId => $question) {
                if (isset($wanted[$repositoryId])) {
                    // An item that closed with no answer ends its question, so a new one opens below.
                    if (null === $question->answer && InboxItemState::Open !== $question->item->state) {
                        $question->endedAt = $now;
                        unset($open[$repositoryId]);
                        $changed = true;
                    }

                    continue;
                }
                if ($held && InboxItemState::Open === $question->item->state) {
                    continue;
                }
                if (InboxItemState::Open === $question->item->state) {
                    $this->closer->close($question->item, InboxItemState::Resolved, InboxCloser::Loupe, InboxCloseCause::FactEnded, null, $now);
                }
                $question->endedAt = $now;
                unset($open[$repositoryId]);
                $changed = true;
            }
            // Doctrine inserts before it updates, so an ended question must reach the database before a new one is inserted.
            $this->em->flush();

            $created = [];
            foreach ($held ? [] : $wanted as $repositoryId => $repository) {
                if (!isset($open[$repositoryId])) {
                    $created[] = $this->open($project, $repository, $now);
                    $changed = true;
                }
            }
            $this->em->flush();
            foreach ($created as $question) {
                $this->searchIndexer->index($question->item);
            }

            return $changed;
        });
    }

    /**
     * Reads the forge before the transaction, so no row lock waits on it.
     *
     * @return array<string, true> the ids of the repositories that the reconcile would ask, and whose open init pull request sets the merge setting
     */
    private function answeredByInitPullRequest(Project $project): array
    {
        $answered = [];
        foreach ($this->forgeRepositories->findByProject($project) as $repository) {
            if (ForgeRepositorySource::Installation !== $repository->source || !$this->mergeApprovals->of($repository)->unanswered(true)) {
                continue;
            }
            if ($this->initPullRequests->for($repository->forge)?->setsMergeSetting($repository) ?? false) {
                $answered[($repository->id ?? throw new \LogicException('A stored repository has an id.'))->toRfc4122()] = true;
            }
        }

        return $answered;
    }

    private function open(Project $project, ForgeRepository $repository, \DateTimeImmutable $now): InboxMergeApprovalQuestion
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Question,
            title: mb_strimwidth($this->translator->trans('inbox.merge_approval.title', ['%repository%' => $repository->path], null, $this->locale), 0, InboxItem::MAX_TITLE_LENGTH, '…'),
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            body: $this->translator->trans('inbox.merge_approval.body', ['%repository%' => $repository->path], null, $this->locale),
            options: [
                $this->translator->trans('inbox.merge_approval.option.agent', [], null, $this->locale),
                $this->translator->trans('inbox.merge_approval.option.person', [], null, $this->locale),
            ],
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        // The question has no dismissal, because merges stay held until it has an answer.
        $item->dismissible = false;
        $question = new InboxMergeApprovalQuestion($project, $repository->id ?? throw new \LogicException('A stored repository has an id.'), $item, $now);
        $this->em->persist($item);
        $this->em->persist($question);
        // The Doctrine transport commits the message with the question.
        $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));

        return $question;
    }

    /** Whole seconds, like the stored columns. */
    private static function whole(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime((int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s'));
    }
}
