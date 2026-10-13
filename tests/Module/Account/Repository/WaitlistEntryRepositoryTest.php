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
}
