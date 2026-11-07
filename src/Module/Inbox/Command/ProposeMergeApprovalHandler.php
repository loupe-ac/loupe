<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\MergeApprovals;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Forge\Service\RepositorySettingProposers;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Opens the pull request that writes the owner's answer into `.loupe.yaml`, and tells the owner where it is.
 * A retry for the same answer finds the open pull request and opens no second one. A refusal that no retry
 * can fix gives the owner the lines to add by hand.
 */
final readonly class ProposeMergeApprovalHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxMergeApprovalQuestionRepository $inboxMergeApprovalQuestions,
        private ForgeRepositoryRepository $forgeRepositories,
        private RepositorySettingProposers $proposers,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    /** @throws PullRequestWriteFailed when the forge failed in a way a retry can fix */
    public function __invoke(ProposeMergeApprovalCommand $command): void
    {
        $item = Uuid::isValid($command->itemId) ? $this->inboxItems->find($command->itemId) : null;
        $question = null === $item ? null : $this->inboxMergeApprovalQuestions->findOneForItem($item);
        if (null === $item || null === $question || null === $question->answer || null !== $question->pullRequestUrl) {
            return;
        }

        $repository = $this->forgeRepositories->findOneByIdAndProjectId($question->repositoryId->toRfc4122(), $item->project->requireId()->toRfc4122());
        $proposer = null === $repository ? null : $this->proposers->for($repository->forge);
        if (null === $repository || null === $proposer) {
            $this->logger->info('inbox.merge_approval_pull_request_skipped', ['itemId' => (string) $item->id, 'repositoryId' => $question->repositoryId->toRfc4122()]);

            return;
        }

        $parameters = ['%repository%' => $repository->path, '%value%' => $question->answer, '%key%' => MergeApprovals::SETTING];
        try {
            $url = $proposer->propose(
                $repository,
                MergeApprovals::SETTING,
                $question->answer,
                $this->translator->trans('inbox.merge_approval.pull_request.title', $parameters, null, $this->locale),
                $this->translator->trans('inbox.merge_approval.pull_request.body', $parameters, null, $this->locale),
            );
        } catch (PullRequestWriteFailed $e) {
            if (!$e->permanent) {
                throw $e;
            }
            $this->logger->info('inbox.merge_approval_pull_request_refused', ['itemId' => (string) $item->id, 'cause' => $e->cause]);
            $item->body = ($item->body ?? '')."\n\n".$this->translator->trans('inbox.merge_approval.refused', $parameters, null, $this->locale);
            $this->em->flush();

            return;
        }

        $question->pullRequestUrl = $url;
        $item->body = ($item->body ?? '')."\n\n".$this->translator->trans('inbox.merge_approval.opened', $parameters + ['%url%' => $url], null, $this->locale);
        $this->em->flush();
    }
}
