<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkRequestRepository;

final readonly class WorkRequestExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_work_requests.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        // The claim token stays out. It is a live credential of a bridge, not data of the user.
        foreach ($this->workRequests->findByOwner($user) as $request) {
            yield [
                'workRequestId' => (string) $request->id,
                'project' => $request->project->name,
                'subjectType' => $request->subjectType,
                'subjectId' => (string) $request->subjectId,
                'cardNumber' => $request->cardNumber,
                'kind' => $request->kind,
                'capability' => $request->capability,
                'ruleId' => $request->ruleId,
                'state' => $request->state->value,
                'bridgeId' => $request->bridgeId?->toRfc4122(),
                'claims' => $request->claims,
                'leaseUntil' => $request->leaseUntil?->format(\DateTimeInterface::ATOM),
                'reason' => $request->reason,
                'waitCause' => $request->waitCause,
                'waitAccount' => $request->waitAccount,
                'waitUntil' => $request->waitUntil?->format(\DateTimeInterface::ATOM),
                'context' => $request->context->toArray(),
                'model' => $request->model,
                'effort' => $request->effort,
                'targetBridgeId' => $request->targetBridgeId?->toRfc4122(),
                'branch' => $request->branch,
                'params' => $request->params,
                'run' => $request->run,
                'createdAt' => $request->createdAt->format(\DateTimeInterface::ATOM),
                'reopenedAt' => $request->reopenedAt?->format(\DateTimeInterface::ATOM),
                'settledAt' => $request->settledAt?->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
