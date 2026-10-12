<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Outbox\OutboxWriter;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Pauses the agents on a card inside the caller's transaction, so the hold and
 * its outbox event roll back with the caller's other writes.
 */
final readonly class CardPause
{
    public function __construct(
        private CardHolds $cardHolds,
        private OutboxWriter $outbox,
        private WorkerRunChangedPublisher $runsChanged,
        private Auditor $auditor,
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdraw,
    ) {
    }

    /**
     * Call it under the project lock, inside a transaction. Answers false when
     * the card is held already, and then writes nothing.
     *
     * @param 'human'|'agent' $actor
     */
    public function take(Project $project, Uuid $cardId, User $requestedBy, string $actor): bool
    {
        if ($this->cardHolds->isHeld($project, $cardId)) {
            return false;
        }

        $this->cardHolds->hold($project, $cardId, $requestedBy);
        $this->outbox->write($project, BridgeEventType::CARD_HELD, [
            'type' => BridgeEventType::CARD_HELD,
            'subject' => ['type' => 'card', 'id' => (string) $cardId],
            'projectId' => (string) $project->id,
            'actor' => $actor,
        ]);

        return true;
    }

    /**
     * Call it after the commit of a take() that answered true. It cancels the open
     * requests of the card and leaves a claimed one to its worker. A release makes
     * the engine open each request the card still needs, so a release that
     * commits before a cancel keeps that request.
     */
    public function announce(Project $project, Uuid $cardId): void
    {
        $this->auditor->record(
            'bridge.card_held',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'cardId' => (string) $cardId],
            new AuditSubject('card', (string) $cardId),
        );
        foreach ($this->workRequests->findLiveForCard($cardId) as $request) {
            if (WorkRequestState::Open === $request->state) {
                ($this->withdraw)(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A persisted request has an id.'), WorkRequestState::Cancelled, onlyIfOpen: true, onlyIfCardHeld: true));
            }
        }
        $this->runsChanged->runsChanged($project);
    }
}
