<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistAccountPurger;
use App\Module\Project\Service\ProjectAccountPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WaitlistAccountPurgerTest extends KernelTestCase
{
    public function test_it_takes_the_waitlist_row_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $leaving = new User(fullName: 'Leaving', email: 'waitlist-purge-leaving@example.com', password: 'x');
        $em->persist($leaving);
        $em->persist(new WaitlistEntry('waitlist-purge-leaving@example.com'));
        $em->persist(new WaitlistEntry('waitlist-purge-staying@example.com'));
        $em->flush();

        $purger = self::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $purger);
        $purger->purge($leaving);

        $em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $entries);
        self::assertNull($entries->findOneByEmail('waitlist-purge-leaving@example.com'));
        self::assertNotNull($entries->findOneByEmail('waitlist-purge-staying@example.com'));
    }

    public function test_it_runs_after_the_project_purger(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $projects = self::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projects);

        self::assertGreaterThan($projects->deletionOrder(), new WaitlistAccountPurger($em)->deletionOrder());
    }
}
