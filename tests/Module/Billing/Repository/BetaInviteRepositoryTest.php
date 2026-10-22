<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Repository;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Tests\Support\BetaWelcomeEmails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BetaInviteRepositoryTest extends KernelTestCase
{
    public function test_find_one_redeemed_by_returns_the_first_redemption(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tester = new User(fullName: 'Tester', email: 'repo-tester@example.com', password: 'x');
        $em->persist($tester);

        // Persisted newest first, so insertion order cannot pass for the answer.
        $later = $this->redeemed($tester, new \DateTimeImmutable('-1 day'));
        $first = $this->redeemed($tester, new \DateTimeImmutable('-30 days'));
        $em->persist($later);
        $em->persist($first);
        $em->flush();
        $em->clear();

        $found = self::getContainer()->get(BetaInviteRepository::class)->findOneRedeemedBy(
            $em->find(User::class, $tester->id) ?? throw new \LogicException('persisted above'),
        );

        self::assertSame($first->id?->toRfc4122(), $found?->id?->toRfc4122());
    }

    public function test_find_welcome_due_returns_due_invites_of_verified_comped_redeemers_oldest_first_up_to_the_limit(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $verified = new User(fullName: 'Verified', email: 'due-verified@example.com', password: 'x');
        $verified->emailVerifiedAt = new \DateTimeImmutable();
        $unverified = new User(fullName: 'Unverified', email: 'due-unverified@example.com', password: 'x');
        $uncomped = new User(fullName: 'Uncomped', email: 'due-uncomped@example.com', password: 'x');
        $uncomped->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($verified);
        $em->persist($unverified);
        $em->persist($uncomped);
        BetaWelcomeEmails::persistComp($em, $verified);
        BetaWelcomeEmails::persistComp($em, $unverified);

        $newer = $this->redeemed($verified, new \DateTimeImmutable('-1 day'));
        $older = $this->redeemed($verified, new \DateTimeImmutable('-3 days'));
        $notVerified = $this->redeemed($unverified, new \DateTimeImmutable('-5 days'));
        $cleared = $this->redeemed($verified, new \DateTimeImmutable('-4 days'));
        $cleared->welcomeEmailDueAt = null;
        $noComp = $this->redeemed($uncomped, new \DateTimeImmutable('-6 days'));
        foreach ([$newer, $older, $notVerified, $cleared, $noComp] as $invite) {
            $em->persist($invite);
        }
        $em->flush();
        $em->clear();

        $repository = self::getContainer()->get(BetaInviteRepository::class);
        $ids = static fn (array $invites): array => array_map(static fn (BetaInvite $i): ?string => $i->id?->toRfc4122(), $invites);

        self::assertSame($ids([$older, $newer]), $ids($repository->findWelcomeDue(100)));
        self::assertSame($ids([$older]), $ids($repository->findWelcomeDue(1)));
    }

    public function test_find_welcome_due_for_returns_every_due_invite_of_one_user_oldest_first(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tester = new User(fullName: 'Tester', email: 'due-for@example.com', password: 'x');
        $other = new User(fullName: 'Other', email: 'due-for-other@example.com', password: 'x');
        $em->persist($tester);
        $em->persist($other);

        $newer = $this->redeemed($tester, new \DateTimeImmutable('-1 day'));
        $older = $this->redeemed($tester, new \DateTimeImmutable('-2 days'));
        $sent = $this->redeemed($tester, new \DateTimeImmutable('-3 days'));
        $sent->welcomeEmailDueAt = null;
        $foreign = $this->redeemed($other, new \DateTimeImmutable('-4 days'));
        foreach ([$newer, $older, $sent, $foreign] as $invite) {
            $em->persist($invite);
        }
        $em->flush();
        $em->clear();

        $found = self::getContainer()->get(BetaInviteRepository::class)->findWelcomeDueFor(
            $em->find(User::class, $tester->id) ?? throw new \LogicException('persisted above'),
        );

        self::assertSame(
            [$older->id?->toRfc4122(), $newer->id?->toRfc4122()],
            array_map(static fn (BetaInvite $i): ?string => $i->id?->toRfc4122(), $found),
        );
    }

    private function redeemed(User $user, \DateTimeImmutable $at): BetaInvite
    {
        [$invite] = BetaInvite::issue(null);
        $invite->redeem($user);
        $invite->redeemedAt = $at;
        $invite->welcomeEmailDueAt = $at;

        return $invite;
    }
}
