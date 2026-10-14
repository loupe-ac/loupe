<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Repository\InboxActionRepository;

final readonly class InboxActionExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxActionRepository $inboxActions,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'inbox_actions.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->inboxActions->findByRequester($user) as $action) {
            yield [
                'id' => (string) $action->id,
                'itemId' => (string) $action->item->id,
                'kind' => $action->kind->value,
                'note' => $action->note,
                'requestedAt' => $action->requestedAt->format(\DateTimeInterface::ATOM),
                'bridgeCommandId' => $action->bridgeCommandId?->toRfc4122(),
                'pauseId' => $action->pauseId?->toRfc4122(),
                'cancelledAt' => $action->cancelledAt?->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
