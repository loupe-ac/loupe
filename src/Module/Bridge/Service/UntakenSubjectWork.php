<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\WorkLedger;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

/**
 * Fails the work about a subject other than a card that no bridge can take, when its request asks for that.
 * The workflow engine does the same for a card.
 */
// Lazy, because the withdraw handler reaches the auditor, and a listener builds this service for each availability change.
#[Lazy]
final readonly class UntakenSubjectWork
{
    public function __construct(
        private WorkLedger $ledger,
        private WithdrawWorkRequestHandler $withdraw,
    ) {
    }

    /** Expires the request with the reason that no bridge can take it. Answers whether it expired. */
    public function expireWhenUntaken(WorkRequest $request): bool
    {
        if (!$request->failWhenNoTaker || WorkRequestState::Open !== $request->state || WorkSubject::CARD === $request->subjectType) {
            return false;
        }

        $id = $request->id ?? throw new \LogicException('A stored request has an id.');

        // The first read takes no lock, so only a request that looks untaken blocks the bridges. The withdrawal reads again.
        return null !== $this->ledger->noTakerReason($id) && ($this->withdraw)(new WithdrawWorkRequestCommand(
            $id,
            WorkRequestState::Expired,
            onlyIfOpen: true,
            outsideWorkHoldOnly: true,
            onlyWhileUntaken: true,
        ));
    }
}
