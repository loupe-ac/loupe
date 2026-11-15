<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Command\Admin\RevokeCompCommand;
use App\Module\Billing\Command\Admin\RevokeCompHandler;
use App\Module\Billing\Command\ClaimBetaInviteCommand;
use App\Module\Billing\Command\ClaimBetaInviteHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Tests\Support\CapturedAccountSignals;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** A change to the billing state of a user reaches the billing pages of that user, and of nobody else. */
final class BillingStateSignalsTest extends KernelTestCase
{
    private CapturedAccountSignals $signals;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->signals = CapturedAccountSignals::install(self::getContainer());
    }

    public function test_a_comp_grant_signals_the_billing_area_of_the_target_and_not_of_the_admin(): void
    {
        [$admin, $target] = $this->users('grant');

        self::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $this->signals->endRequest();

        self::assertSame(['billing'], $this->signals->areasOf($this->id($target)));
        self::assertSame([], $this->signals->areasOf($this->id($admin)));
    }

    public function test_a_refused_comp_grant_adds_no_signal(): void
    {
        [$admin, $target] = $this->users('grantrefused');
        $grant = self::getContainer()->get(GrantCompHandler::class);
        $grant(new GrantCompCommand($target, $admin));
        $this->signals->endRequest();
        $before = $this->signals->areasOf($this->id($target));

        try {
            $grant(new GrantCompCommand($target, $admin));
            self::fail('A second comp must be refused.');
        } catch (DomainErrors) {
            $this->signals->endRequest();
        }

        self::assertSame(['billing'], $before);
        self::assertSame($before, $this->signals->areasOf($this->id($target)));
    }

    public function test_a_comp_revoke_signals_the_billing_area_of_the_target(): void
    {
        [$admin, $target] = $this->users('revoke');
        self::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $this->signals->endRequest();

        self::getContainer()->get(RevokeCompHandler::class)(new RevokeCompCommand($target, $admin));
        $this->signals->endRequest();

        self::assertSame(['billing', 'billing'], $this->signals->areasOf($this->id($target)));
    }

    public function test_a_revoke_of_a_comp_that_does_not_exist_signals_nothing(): void
    {
        [$admin, $target] = $this->users('revokerefused');

        try {
            self::getContainer()->get(RevokeCompHandler::class)(new RevokeCompCommand($target, $admin));
            self::fail('Revoking a missing comp must be refused.');
        } catch (DomainErrors) {
            $this->signals->endRequest();
        }

        self::assertSame([], $this->signals->areasOf($this->id($target)));
    }

    public function test_a_claimed_beta_invite_signals_the_billing_area_of_the_tester(): void
    {
        [$admin, $tester] = $this->users('claim');
        [$invite, $token] = BetaInvite::issue($admin, 'signal test');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($invite);
        $em->flush();

        self::getContainer()->get(ClaimBetaInviteHandler::class)(new ClaimBetaInviteCommand($token, $tester));
        $this->signals->endRequest();

        self::assertSame(['billing'], $this->signals->areasOf($this->id($tester)));
    }

    public function test_an_unknown_beta_token_signals_nothing(): void
    {
        [, $tester] = $this->users('claimunknown');

        self::getContainer()->get(ClaimBetaInviteHandler::class)(new ClaimBetaInviteCommand(str_repeat('0', 64), $tester));
        $this->signals->endRequest();

        self::assertSame([], $this->signals->areasOf($this->id($tester)));
    }

    /** @return array{User, User} */
    private function users(string $prefix): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('Admin', $prefix.'-admin@example.com', 'hashed-password-placeholder');
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();
        $target = new User('Target', $prefix.'-target@example.com', 'hashed-password-placeholder');
        $target->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($admin);
        $em->persist($target);
        $em->flush();

        return [$admin, $target];
    }

    private function id(User $user): Uuid
    {
        return $user->id ?? throw new \LogicException('A flushed user has an id.');
    }
}
