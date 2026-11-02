<?php

declare(strict_types=1);

namespace App\Module\Account\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The dev and admin accounts keep the id they have, because an existing
 * database points projects and tokens at them. The demo accounts get fixed ids.
 */
#[When('dev')]
#[When('test')]
final readonly class DevAccountSeeder implements DevSeederInterface
{
    public const string KEY = 'accounts';
    public const string MEMBER_EMAIL = 'member@loupe.test';
    public const string SUSPENDED_EMAIL = 'suspended@loupe.test';
    public const string PASSWORD = 'password';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $this->seedUser(SeedContext::DEV_EMAIL, 'Dev User', [], $context->now);
        $this->seedUser(SeedContext::ADMIN_EMAIL, 'Admin User', ['ROLE_ADMIN'], $context->now);

        $this->seedDemo($context, 'account/user/member', self::MEMBER_EMAIL, 'Member User', false, $context->at(2));
        $this->seedDemo($context, 'account/user/suspended', self::SUSPENDED_EMAIL, 'Suspended User', true, $context->at(1));
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(string $email, string $fullName, array $roles, \DateTimeImmutable $now): void
    {
        $user = $this->users->findOneByEmail($email);
        if ($user instanceof User) {
            // A demoted account would keep the documented credentials from reaching /admin.
            $missing = array_diff($roles, $user->roles);
            if ([] !== $missing) {
                $user->roles = array_values(array_unique([...$user->roles, ...$roles]));
                $this->em->flush();
            }

            return;
        }

        $user = $this->newUser($email, $fullName, $now, $now);
        $user->roles = $roles;
        $this->em->persist($user);
        $this->em->flush();
    }

    /** @param non-empty-string $email */
    private function seedDemo(SeedContext $context, string $key, string $email, string $fullName, bool $suspended, \DateTimeImmutable $createdAt): void
    {
        $id = DevSeedId::of($key);
        if ($this->users->find($id) instanceof User) {
            return;
        }

        $old = $this->users->findOneByEmail($email);
        if ($old instanceof User) {
            $old->email = \sprintf('old-%s+%s', substr((string) $old->id, 0, 8), $email);
            $this->em->flush();
        }

        $user = $this->newUser($email, $fullName, $context->now, $createdAt);
        if ($suspended) {
            $user->suspendedAt = $context->now;
            $user->suspendedReason = 'Suspended demo account.';
        }
        $context->ids->assign($user, $id);
        $this->em->persist($user);
        $this->em->flush();
    }

    /** @param non-empty-string $email */
    private function newUser(string $email, string $fullName, \DateTimeImmutable $now, \DateTimeImmutable $createdAt): User
    {
        $user = new User(fullName: $fullName, email: $email, createdAt: $createdAt);
        $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);
        // A seeded login must land on the app, not on a verification or terms page.
        $user->emailVerifiedAt = $now;
        $user->termsAcceptedAt = $now;
        $user->termsVersion = $this->termsVersion;

        return $user;
    }
}
