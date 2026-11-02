<?php

declare(strict_types=1);

namespace App\DevSeed;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;

final readonly class SeedContext
{
    public const string DEV_EMAIL = 'dev@loupe.test';
    public const string ADMIN_EMAIL = 'admin@loupe.test';

    public function __construct(
        public \DateTimeImmutable $now,
        public DevSeedIds $ids,
        private UserRepository $users,
        public bool $keepFlagDefaults = false,
    ) {
    }

    public function devUser(): User
    {
        return $this->account(self::DEV_EMAIL);
    }

    public function adminUser(): User
    {
        return $this->account(self::ADMIN_EMAIL);
    }

    public function at(int $secondsAgo): \DateTimeImmutable
    {
        return $this->now->modify(\sprintf('-%d seconds', $secondsAgo));
    }

    private function account(string $email): User
    {
        return $this->users->findOneByEmail($email)
            ?? throw new \LogicException(\sprintf('The account %s does not exist yet. A seeder that needs it must depend on the accounts seeder.', $email));
    }
}
