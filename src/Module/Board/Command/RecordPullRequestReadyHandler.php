<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a `ready-to-merge` card event for each card that links the pull
 * request. Forge calls this inside the transaction of its read. The event
 * records a fact: it starts no work and moves no card. The card page hears
 * of it through the `updated` signal that WritePullRequestFactEventsHandler
 * sends for the same change of readyToMerge.
 */
final readonly class RecordPullRequestReadyHandler
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RecordPullRequestReadyCommand $command): void
    {
        $pullRequest = $command->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        if (null === $forge) {
            return;
        }

        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        /** @var array<string, Card> $cards */
        $cards = [];
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            // Two spellings of one URL on one card are two links, and the card must hear once.
            $cards[(string) $link->card->id] ??= $link->card;
        }
        if ([] === $cards) {
            return;
        }

        $detail = [
            'pullRequest' => $pullRequest->number,
            'pullRequestId' => ($pullRequest->id ?? throw new \LogicException('A stored pull request has an id.'))->toRfc4122(),
        ];
        foreach ($cards as $card) {
            $this->cardEvents->record($card, CardEventKind::ReadyToMerge, Actor::System, null, $detail, $command->readyAt);
        }
        $this->em->flush();
    }
}
