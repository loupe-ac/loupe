<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service\Dev;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevAccountSeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_creates_the_accounts_and_the_demo_accounts_with_fixed_ids(): void
    {
        $this->clearAccounts();
        $this->runSeeder();

        $users = $this->users();
        $dev = $users->findOneByEmail(SeedContext::DEV_EMAIL);
        self::assertInstanceOf(User::class, $dev);
        self::assertNotNull($dev->emailVerifiedAt);
        self::assertFalse($dev->id?->equals(DevSeedId::of('account/user/member')));
        $admin = $users->findOneByEmail(SeedContext::ADMIN_EMAIL);
        self::assertInstanceOf(User::class, $admin);
        self::assertContains('ROLE_ADMIN', $admin->roles);

        $member = $users->findOneByEmail(DevAccountSeeder::MEMBER_EMAIL);
        self::assertTrue($member?->id?->equals(DevSeedId::of('account/user/member')));
        self::assertFalse($member->isSuspended());
        $suspended = $users->findOneByEmail(DevAccountSeeder::SUSPENDED_EMAIL);
        self::assertTrue($suspended?->id?->equals(DevSeedId::of('account/user/suspended')));
        self::assertTrue($suspended->isSuspended());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->clearAccounts();
        $this->runSeeder();
        $before = $this->users()->count([]);
        $devId = $this->users()->findOneByEmail(SeedContext::DEV_EMAIL)?->id;
        $this->runSeeder();

        self::assertSame($before, $this->users()->count([]));
        self::assertSame($devId?->toRfc4122(), $this->users()->findOneByEmail(SeedContext::DEV_EMAIL)?->id?->toRfc4122());
    }

    public function test_it_keeps_the_id_of_an_existing_dev_account_and_restores_the_admin_role(): void
    {
        $this->clearAccounts();
        $em = $this->em();
        $dev = new User('Old Dev', SeedContext::DEV_EMAIL);
        $admin = new User('Old Admin', SeedContext::ADMIN_EMAIL);
        $em->persist($dev);
        $em->persist($admin);
        $em->flush();
        $devId = $dev->id?->toRfc4122();
        $adminId = $admin->id?->toRfc4122();

        $this->runSeeder();
        $em->clear();

        self::assertSame($devId, $this->users()->findOneByEmail(SeedContext::DEV_EMAIL)?->id?->toRfc4122());
        $adminAfter = $this->users()->findOneByEmail(SeedContext::ADMIN_EMAIL);
        self::assertInstanceOf(User::class, $adminAfter);
        self::assertSame($adminId, $adminAfter->id?->toRfc4122());
        self::assertContains('ROLE_ADMIN', $adminAfter->roles);
    }

    public function test_it_renames_a_demo_email_that_has_another_id(): void
    {
        $this->clearAccounts();
        $em = $this->em();
        $old = new User('Old Member', DevAccountSeeder::MEMBER_EMAIL);
        $em->persist($old);
        $em->flush();
        $oldId = $old->id;
        self::assertNotNull($oldId);

        $this->runSeeder();
        $em->clear();

        $renamed = $em->find(User::class, $oldId);
        self::assertSame(\sprintf('old-%s+%s', substr((string) $oldId, 0, 8), DevAccountSeeder::MEMBER_EMAIL), $renamed?->email);
        self::assertTrue($this->users()->findOneByEmail(DevAccountSeeder::MEMBER_EMAIL)?->id?->equals(DevSeedId::of('account/user/member')));
    }

    private function runSeeder(): void
    {
        self::getContainer()->get(DevAccountSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));
    }

    private function clearAccounts(): void
    {
        foreach ([SeedContext::DEV_EMAIL, SeedContext::ADMIN_EMAIL, DevAccountSeeder::MEMBER_EMAIL, DevAccountSeeder::SUSPENDED_EMAIL] as $email) {
            $user = $this->users()->findOneByEmail($email);
            if ($user instanceof User) {
                $this->em()->remove($user);
            }
        }
        $this->em()->flush();
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
