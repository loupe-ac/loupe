<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Messenger\ResumeWaitingSession;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Workflow\Contract\CardWorkerWaits;
use App\Module\Workflow\Contract\OpenWait;
use App\Module\Workflow\Contract\WaitedCondition;
use App\Module\Workflow\Contract\WaitEnd;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gives the engine the open wait of a card, and ends it for the engine. A wait that ends while the run of the
 * worker is parked asks for the resume of the session at once. While the run still ends its turn, the end of the run asks.
 *
 * @phpstan-import-type ConditionEntry from WorkerWait
 */
#[AsAlias(CardWorkerWaits::class)]
final readonly class BridgeCardWorkerWaits implements CardWorkerWaits
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerRunRepository $workerRuns,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function openFor(Uuid $cardId): ?OpenWait
    {
        $wait = $this->workerWaits->findOpenOfCard($cardId);
        if (null === $wait) {
            return null;
        }

        return new OpenWait(
            $wait->id ?? throw new \LogicException('A stored wait has an id.'),
            $wait->openedAt,
            $wait->limitAt,
            self::conditionsOf($wait),
        );
    }

    #[\Override]
    public function end(Uuid $waitId, WaitEnd $reason, array $met): void
    {
        $wait = $this->workerWaits->find($waitId);
        if (null === $wait) {
            return;
        }

        $entries = array_map(static fn (WaitedCondition $condition): array => [
            'key' => $condition->key,
            'params' => $condition->params,
            'pullRequestId' => $condition->pullRequestId?->toRfc4122(),
            'token' => $condition->token,
        ], $met);
        if (!$wait->end(WorkerWaitEndReason::fromEnd($reason), $this->clock->now(), $entries)) {
            return;
        }
        $this->em->flush();

        $run = $this->workerRuns->find($wait->runId);
        if (WorkerRunState::Waiting === $run?->state) {
            $this->bus->dispatch(new ResumeWaitingSession($waitId->toRfc4122()));
        }
    }

    /** @return list<WaitedCondition> */
    public static function conditionsOf(WorkerWait $wait): array
    {
        return array_map(static fn (array $entry): WaitedCondition => new WaitedCondition(
            $entry['key'],
            $entry['params'],
            null === $entry['pullRequestId'] ? null : Uuid::fromString($entry['pullRequestId']),
            $entry['token'],
        ), $wait->conditions);
    }
}
