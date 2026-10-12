<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Stores one notice for a pull request and a key, and queues its post. */
final readonly class PullRequestNoticeQueue
{
    public function __construct(
        private PullRequestNoticeRepository $pullRequestNotices,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** Answers whether it stored a notice. A key that already has a row stores nothing. */
    public function queue(ForgePullRequest $pullRequest, string $key): bool
    {
        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $pullRequestId = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        // A DBAL transaction nests as a savepoint in the transaction of the evaluation.
        $noticeId = $this->em->getConnection()->transactional(function () use ($projectId, $pullRequestId, $pullRequest, $key) {
            $noticeId = $this->pullRequestNotices->insertIfMissing($projectId, $pullRequestId, $pullRequest->forge, $pullRequest->repository, $pullRequest->number, $key, $this->clock->now());
            if (null !== $noticeId) {
                $this->bus->dispatch(new PostPullRequestNotice($noticeId));
            }

            return $noticeId;
        });
        if (null === $noticeId) {
            return false;
        }

        $this->logger->info('board.pull_request_notice_queued', [
            'noticeId' => (string) $noticeId,
            'projectId' => (string) $projectId,
            'forgePullRequestId' => (string) $pullRequestId,
            'noticeKey' => $key,
        ]);

        return true;
    }
}
