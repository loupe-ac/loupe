<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Repository\InboxDecisionSeenRepository;

/** When the person last opened the Decisions tab of each card. It reads no feature flag, as the other inbox exporters do. */
final readonly class InboxDecisionSeenExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxDecisionSeenRepository $inboxDecisionSeens,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'inbox_decision_seen.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->inboxDecisionSeens->findByUser($user) as $row) {
            yield [
                'id' => (string) $row->id,
                'projectId' => (string) $row->project->id,
                'cardId' => (string) $row->card->id,
                'seenAt' => $row->seenAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
