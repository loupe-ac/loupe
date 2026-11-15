<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateProfileHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private AccountChangedPublisher $accountChanged,
    ) {
    }

    public function __invoke(UpdateProfileCommand $command): User
    {
        $user = $command->user;
        $fullName = trim($command->fullName);

        // A submit that changes nothing is accepted, not refused — and an audit
        // record would claim a transition the database never made.
        if ($fullName === $user->fullName) {
            return $user;
        }

        $user->fullName = $fullName;
        $this->em->flush();
        $this->accountChanged->changed($user->id ?? throw new \LogicException('a persisted user always has an id'), AccountArea::PROFILE);

        $this->auditor->record(
            'account.profile_updated',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );

        return $user;
    }
}
