<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

/** Which kinds of work already ended done at the current head of each open pull request of the card. */
final readonly class WorkAtHeadFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $trackedPullRequests,
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkAtHeadFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $snapshot): object
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('The card of the facts exists.');

        $heads = [];
        $numbers = [];
        foreach ($this->trackedPullRequests->forCard($card) as $pullRequest) {
            if (PullRequestState::Open !== $pullRequest->state || null === $pullRequest->headSha || null === $pullRequest->id) {
                continue;
            }
            $heads[$pullRequest->id->toRfc4122()] = mb_strtolower($pullRequest->headSha);
            $numbers[$pullRequest->id->toRfc4122()] = $pullRequest->number;
        }

        $done = [];
        foreach ($this->workRequests->findDoneAtHeads($snapshot->id, array_values($heads)) as $row) {
            foreach ($heads as $id => $head) {
                if ($head === $row['headSha'] && $numbers[$id] === $row['pullRequestNumber']) {
                    $done[$id][$row['kind']] = $row['kind'];
                }
            }
        }
        ksort($heads);
        ksort($done);

        return new WorkAtHeadFacts(array_map(static function (array $kinds): array {
            $kinds = array_values($kinds);
            sort($kinds);

            return $kinds;
        }, $done), $heads);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof WorkAtHeadFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [$facts->heads, $facts->doneKinds];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
