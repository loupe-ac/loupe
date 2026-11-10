<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistPick;
use App\Module\Account\Repository\WaitlistBlockPatternRepository;
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

    public function test_an_entry_that_matches_a_block_pattern_is_hidden_from_every_list_and_count(): void
    {
        [$em, $repo] = $this->services();
        $before = [
            'total' => \count($repo->findPaginated(1, 1000, 'createdAt', 'asc')),
            'waiting' => $repo->countWaiting(),
            'hidden' => $repo->countHidden(),
            'picks' => $repo->pickCounts(),
        ];

        $visible = new WaitlistEntry('visible@shown.example', new \DateTimeImmutable('-20 years'));
        $visible->mergePicks([WaitlistPick::Pi], null);
        $hidden = new WaitlistEntry('someone@Hidden.example', new \DateTimeImmutable('-21 years'));
        $hidden->mergePicks([WaitlistPick::Pi, WaitlistPick::GitLab], null);
        $hiddenConverted = new WaitlistEntry('converted@hidden.example');
        $hiddenConverted->markConverted();
        foreach ([$visible, $hidden, $hiddenConverted] as $entry) {
            $em->persist($entry);
        }
        $em->persist(new WaitlistBlockPattern('*@hidden.example'));
        $em->flush();

        $listed = iterator_to_array($repo->findPaginated(1, 1000, 'createdAt', 'asc'));
        self::assertContains($visible, $listed);
        self::assertNotContains($hidden, $listed);
        self::assertNotContains($hiddenConverted, $listed);
        self::assertSame($before['total'] + 1, \count($repo->findPaginated(1, 1000, 'createdAt', 'asc')));
        self::assertSame($before['waiting'] + 1, $repo->countWaiting());
        self::assertSame($before['hidden'] + 2, $repo->countHidden());

        $picks = $repo->pickCounts();
        self::assertSame($before['picks']['pi'] + 1, $picks['pi']);
        self::assertSame($before['picks']['gitlab'], $picks['gitlab']);

        $oldest = $repo->findOldestUninvited(1);
        self::assertSame([$visible], $oldest);

        self::assertSame($visible, $repo->findOneVisible((string) $visible->id));
        self::assertNull($repo->findOneVisible((string) $hidden->id));
        self::assertNull($repo->findOneVisible('0193a9c2-0000-7000-8000-000000000000'));
    }

    public function test_removing_the_pattern_shows_the_entry_again(): void
    {
        [$em, $repo] = $this->services();
        $entry = new WaitlistEntry('back@again.example', new \DateTimeImmutable('-20 years'));
        $pattern = new WaitlistBlockPattern('*@again.example');
        $em->persist($entry);
        $em->persist($pattern);
        $em->flush();
        $hiddenBefore = $repo->countHidden();
        self::assertNull($repo->findOneVisible((string) $entry->id));

        $em->remove($pattern);
        $em->flush();

        self::assertSame($hiddenBefore - 1, $repo->countHidden());
        self::assertSame($entry, $repo->findOneVisible((string) $entry->id));
        self::assertSame([$entry], $repo->findOldestUninvited(1));
    }

    public function test_identity_lookups_still_find_a_hidden_entry(): void
    {
        [$em, $repo] = $this->services();
        $entry = new WaitlistEntry('identity@hidden-identity.example');
        $token = $entry->issueInviteToken();
        $em->persist($entry);
        $em->persist(new WaitlistBlockPattern('*@hidden-identity.example'));
        $em->flush();

        self::assertSame($entry, $repo->findOneByEmail('identity@hidden-identity.example'));
        self::assertSame($entry, $repo->findOneByValidInviteToken($token));
    }

    /** @return array{EntityManagerInterface, WaitlistEntryRepository} */
    private function services(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $patterns = self::getContainer()->get(WaitlistBlockPatternRepository::class);
        self::assertInstanceOf(WaitlistBlockPatternRepository::class, $patterns);
        foreach ($patterns->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        return [$em, $repo];
    }
}
