<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistPick;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WaitlistEntryRepositoryTest extends KernelTestCase
{
    public function test_pick_counts_count_each_pick_once_per_entry_and_list_the_most_picked_first(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $before = $repo->pickCounts();
        foreach ([
            ['counts-a@example.com', [WaitlistPick::Pi, WaitlistPick::GitLab]],
            ['counts-b@example.com', [WaitlistPick::Pi]],
            ['counts-c@example.com', []],
        ] as [$email, $picks]) {
            $entry = new WaitlistEntry($email);
            $entry->mergePicks($picks, null);
            $em->persist($entry);
        }
        $em->flush();

        $counts = $repo->pickCounts();

        self::assertCount(\count(WaitlistPick::cases()), $counts);
        self::assertSame($before['pi'] + 2, $counts['pi']);
        self::assertSame($before['gitlab'] + 1, $counts['gitlab']);
        self::assertSame($before['aider'], $counts['aider']);
        $values = array_values($counts);
        $sorted = $values;
        rsort($sorted);
        self::assertSame($sorted, $values);
    }

    public function test_count_waiting_counts_only_entries_the_admin_page_shows_as_waiting(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $before = $repo->countWaiting();

        $waiting = new WaitlistEntry('waiting@example.com');
        $invited = new WaitlistEntry('invited@example.com');
        $invited->issueInviteToken();
        $expired = new WaitlistEntry('expired@example.com');
        $expired->issueInviteToken(new \DateTimeImmutable('-1 day'));
        $converted = new WaitlistEntry('converted@example.com');
        $converted->markConverted();
        foreach ([$waiting, $invited, $expired, $converted] as $entry) {
            $em->persist($entry);
        }
        $em->flush();

        self::assertSame($before + 1, $repo->countWaiting());
    }

    public function test_find_one_claimed_by_email_ignores_an_unclaimed_entry(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $claimed = new WaitlistEntry('claimed@example.com');
        $claimed->issueInviteToken();
        $claimed->claimInvite();
        $plain = new WaitlistEntry('plain@example.com');
        $plain->markConverted();
        $em->persist($claimed);
        $em->persist($plain);
        $em->flush();

        self::assertSame($claimed, $repo->findOneClaimedByEmail('Claimed@Example.com'));
        self::assertNull($repo->findOneClaimedByEmail('plain@example.com'));
        self::assertNull($repo->findOneClaimedByEmail('nobody@example.com'));
    }
}
