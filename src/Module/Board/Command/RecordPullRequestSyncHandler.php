<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\CardChangeAnnouncer;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Marks each unfinished card that links the pull request as synced. Forge calls
 * this inside the transaction of its read, after the project lock.
 */
final readonly class RecordPullRequestSyncHandler
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
        private CardChangeAnnouncer $cardChanges,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordPullRequestSyncCommand $command): void
    {
        $pullRequest = $command->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $this->logger->info('board.pull_request_synced', [
            'pullRequestId' => (string) $pullRequest->id,
            'projectId' => (string) $projectId,
            'forge' => $pullRequest->forge,
            'repository' => $pullRequest->repository,
            'pullRequestNumber' => $pullRequest->number,
            'headSha' => $pullRequest->headSha,
        ]);
        if (null === $forge) {
            return;
        }

        /** @var array<string, Card> $cards */
        $cards = [];
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            if (!$link->card->column->terminal) {
                $cards[(string) $link->card->id] ??= $link->card;
            }
        }
        if ([] === $cards) {
            return;
        }

        $now = $this->clock->now();
        $this->em->wrapInTransaction(function () use ($cards, $pullRequest, $now): void {
            foreach ($cards as $card) {
                $this->cardEvents->record($card, CardEventKind::Synced, Actor::System, null, ['pullRequest' => $pullRequest->number], $now);
                $this->em->flush();
            }
        });

        // Inside the transaction of Forge, so `updated` alone: a rollback costs a page one needless refetch.
        $this->cardChanges->updated($projectId, array_values(array_map(
            static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A linked card has an id.'),
            $cards,
        )));
    }
}
