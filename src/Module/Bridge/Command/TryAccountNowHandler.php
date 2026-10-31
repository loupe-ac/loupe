<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\BridgeAccountTryNow;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Bridge\Repository\BridgeAccountTryNowRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Project\Repository\ProjectRepository;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Lets a bridge account take work although it is at a limit, until the first reading
 * after the press or for 15 minutes. It sends the bridge a try-now command, and it pushes
 * the offers of the waiting work that the bridge matched to the account. A second press
 * while the override holds changes nothing.
 */
final readonly class TryAccountNowHandler
{
    public const string UNKNOWN_BRIDGE = 'bridge.try_now.error.unknown_bridge';
    public const string UNKNOWN_ACCOUNT = 'bridge.try_now.error.unknown_account';
    public const string BRIDGE_OUTDATED = 'bridge.try_now.error.bridge_outdated';

    /** The most offers one press pushes. A heartbeat offers the rest. */
    public const int MAX_PUSHED_OFFERS = 100;

    public function __construct(
        private BridgeRepository $bridges,
        private BridgeAccountTryNowRepository $bridgeAccountTryNows,
        private WorkRequestRepository $workRequests,
        private ProjectRepository $projects,
        private BridgeCommandTtl $ttl,
        private OutboxWriter $outbox,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(TryAccountNowCommand $command): BridgeAccountTryNow
    {
        $owner = $command->project->owner;
        $ownerId = (string) ($owner->id ?? throw new \LogicException('A project owner always has an id.'));
        $projectId = (string) ($command->project->id ?? throw new \LogicException('A stored project has an id.'));

        /** @var array{BridgeAccountTryNow, bool}|string $result */
        $result = $this->em->wrapInTransaction(function () use ($command, $owner, $ownerId, $projectId): array|string {
            // The same lock the heartbeat takes, so a heartbeat cannot close the override between the read and the write.
            $this->bridges->lockForWrite($ownerId, $command->bridge->id);

            $bridge = $this->bridges->findOneByOwnerAndId($owner, $command->bridge->id);
            if (null === $bridge || !\in_array($projectId, $bridge->projects, true)) {
                return self::UNKNOWN_BRIDGE;
            }
            if (!$bridge->takesTryNow()) {
                return self::BRIDGE_OUTDATED;
            }
            if (!array_any($bridge->accounts ?? [], static fn (array $row): bool => $row['name'] === $command->account)) {
                return self::UNKNOWN_ACCOUNT;
            }

            $now = $this->clock->now();
            $held = $this->bridgeAccountTryNows->findUnclosed($bridge, $command->account);
            if (null !== $held) {
                if ($held->isOpenAt($now)) {
                    return [$held, false];
                }
                // Its minutes ran out with no reading. The unique index counts it as open until it closes.
                $held->closedAt = $held->expiresAt();
                $this->em->flush();
            }

            $override = new BridgeAccountTryNow(Uuid::v7(), $bridge, $command->account, $now);
            $this->em->persist($override);

            $bridgeCommand = new BridgeCommand(
                owner: $owner,
                bridgeId: $bridge->id,
                project: $command->project,
                workerRun: null,
                kind: BridgeCommandKind::TryNow,
                requestedBy: $command->requestedBy,
                requestedAt: $now,
                expiresAt: $this->ttl->expiresAt($now),
                account: $command->account,
            );
            $this->em->persist($bridgeCommand);
            // The payload names the command, so the row needs its id first.
            $this->em->flush();
            $this->outbox->write($command->project, BridgeEventType::COMMAND, BridgeCommandPayload::of($bridgeCommand));

            foreach ($this->workRequests->findOpenMatchedToAccount($bridge, $command->account, $now, self::MAX_PUSHED_OFFERS) as $request) {
                $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
            }
            $this->em->flush();

            return [$override, true];
        });

        if (\is_string($result)) {
            throw new DomainErrors(['bridge' => $result]);
        }

        [$override, $created] = $result;
        if ($created) {
            $this->auditor->record(
                'bridge.try_now_pressed',
                AuditOutcome::Success,
                ['bridgeId' => (string) $command->bridge->id, 'account' => $command->account, 'projectId' => $projectId],
                new AuditSubject('bridge', (string) $command->bridge->id),
            );
            foreach ($this->projects->findOwnedBy($owner, $command->bridge->projects) as $project) {
                $this->runsChanged->runsChanged($project);
            }
            $this->events->dispatch(new BridgeAvailabilityChanged($command->bridge->projects));
        }

        return $override;
    }
}
