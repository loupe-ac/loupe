<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The discovery request expired with no taker, or the engine cancelled it because the card left the Backlog. */
#[AsEventListener]
final readonly class FailDiscoveryOnRequestWithdrawn
{
    public const string NO_TAKER = WorkRequest::REASON_NO_TAKER;
    public const string CARD_MOVED = 'card-moved';

    public function __construct(
        private WorkRequestRepository $workRequests,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $cardId = $event->cardId();
        if (null === $cardId || !\in_array($event->state, [WorkRequestState::Expired, WorkRequestState::Cancelled], true)) {
            return;
        }
        $request = $this->workRequests->find($event->workRequestId);
        if (null === $request || !\in_array($request->ruleId, [FailDiscoveryRunHandler::RULE_ID, FailDiscoveryRunHandler::REVISE_RULE_ID], true)) {
            return;
        }
        // An expiry that names why no bridge could take the request keeps that reason.
        $reason = WorkRequestState::Expired === $event->state ? ($request->reason ?? self::NO_TAKER) : self::CARD_MOVED;

        // The handler cancels the request after it fails the run, so that cancel finds no requested run.
        ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($cardId, $reason, $request->ruleId));
    }
}
