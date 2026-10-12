<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Workflow\Contract\RunView;
use App\Module\Workflow\Contract\WithdrawKind;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Contract\WorkView;
use Symfony\Component\Uid\Uuid;

/** Reads no live work while hiding is on, as when that work settles between the open attempt and the read. */
final class LiveHidingWorkLedger implements WorkLedger
{
    public bool $hiding = false;

    public function __construct(
        private readonly WorkLedger $inner,
    ) {
    }

    #[\Override]
    public function live(Uuid $cardId): array
    {
        return $this->hiding ? [] : $this->inner->live($cardId);
    }

    #[\Override]
    public function find(Uuid $requestId): ?WorkView
    {
        return $this->inner->find($requestId);
    }

    #[\Override]
    public function withdraw(Uuid $requestId, WithdrawKind $kind): bool
    {
        return $this->inner->withdraw($requestId, $kind);
    }

    #[\Override]
    public function latestContinuation(Uuid $cardId, ?\DateTimeImmutable $since, ?string $ruleId): ?RunView
    {
        return $this->inner->latestContinuation($cardId, $since, $ruleId);
    }

    #[\Override]
    public function isOpenWorkerOnCard(string $runId, Uuid $projectId, Uuid $cardId): bool
    {
        return $this->inner->isOpenWorkerOnCard($runId, $projectId, $cardId);
    }

    #[\Override]
    public function isHeld(Uuid $projectId, Uuid $cardId): bool
    {
        return $this->inner->isHeld($projectId, $cardId);
    }
}
