<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Account\Repository\WaitlistEntryRepository;

final readonly class WaitlistExporter implements UserDataExporterInterface
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'waitlist.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $entry = $this->waitlistEntries->findOneByEmail($user->email);
        if (null === $entry) {
            return;
        }

        yield [
            'joinedAt' => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'picks' => $entry->picks,
            'other' => $entry->otherPick,
        ];
    }
}
