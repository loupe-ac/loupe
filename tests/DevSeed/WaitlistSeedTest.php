<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\Dev\DevWaitlistSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WaitlistSeedTest extends KernelTestCase
{
    public function test_the_seed_writes_visible_and_hidden_entries_and_their_patterns_once(): void
    {
        $this->seed();
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $entries);

        foreach (['ada', 'grace', 'linus'] as $key) {
            self::assertInstanceOf(WaitlistEntry::class, $entries->findOneVisible(DevWaitlistSeeder::entryId($key)), $key);
        }
        foreach (['blocked-domain', 'blocked-prefix'] as $key) {
            self::assertInstanceOf(WaitlistEntry::class, $entries->find(DevWaitlistSeeder::entryId($key)), $key);
            self::assertNull($entries->findOneVisible(DevWaitlistSeeder::entryId($key)), $key);
        }
        foreach (['mailinator', 'spam-gmail'] as $key) {
            self::assertInstanceOf(WaitlistBlockPattern::class, $em->find(WaitlistBlockPattern::class, DevWaitlistSeeder::patternId($key)), $key);
        }
        self::assertCount(1, $entries->findBy(['email' => 'ada@waitlist.example']));
        self::assertGreaterThanOrEqual(2, $entries->countHidden());
    }

    private function seed(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
