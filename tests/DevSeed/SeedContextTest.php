<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedIds;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeedContextTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $users = self::getContainer()->get(UserRepository::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([SeedContext::DEV_EMAIL, SeedContext::ADMIN_EMAIL] as $email) {
            $user = $users->findOneByEmail($email);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        $em->flush();
    }

    public function test_the_accounts_throw_and_name_the_email_before_the_accounts_seeder_ran(): void
    {
        $context = $this->context();

        try {
            $context->devUser();
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString(SeedContext::DEV_EMAIL, $e->getMessage());
        }

        $this->expectExceptionMessage(SeedContext::ADMIN_EMAIL);
        $context->adminUser();
    }

    public function test_the_accounts_come_back_after_the_accounts_seeder_ran(): void
    {
        $context = $this->context();
        self::getContainer()->get(DevAccountSeeder::class)->seed($context);

        self::assertSame(SeedContext::DEV_EMAIL, $context->devUser()->email);
        self::assertContains('ROLE_ADMIN', $context->adminUser()->roles);
    }

    public function test_at_goes_back_from_now(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 12:00:00');
        $context = new SeedContext($now, new DevSeedIds(), self::getContainer()->get(UserRepository::class));

        self::assertEquals(new \DateTimeImmutable('2026-11-01 11:58:00'), $context->at(120));
        self::assertEquals($now, $context->at(0));
    }

    private function context(): SeedContext
    {
        return new SeedContext(new \DateTimeImmutable(), new DevSeedIds(), self::getContainer()->get(UserRepository::class));
    }
}
