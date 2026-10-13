<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WaitlistAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 75;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM waitlist_entries WHERE email = :email',
            ['email' => strtolower($user->email)],
        );
    }
}
