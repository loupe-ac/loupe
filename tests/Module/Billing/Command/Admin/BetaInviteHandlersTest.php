<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\CreateBetaInviteCommand;
use App\Module\Billing\Command\Admin\CreateBetaInviteHandler;
use App\Module\Billing\Command\Admin\ReissueBetaInviteCommand;
use App\Module\Billing\Command\Admin\ReissueBetaInviteHandler;
use App\Module\Billing\Command\Admin\RevokeBetaInviteCommand;
use App\Module\Billing\Command\Admin\RevokeBetaInviteHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditOutcome;

final class BetaInviteHandlersTest extends KernelTestCase
{
    public function test_create_stores_the_hash_and_records_no_token(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $admin = $this->user('create-admin');

        $view = self::getContainer()->get(CreateBetaInviteHandler::class)(new CreateBetaInviteCommand($admin, 'reddit u/foo'));

        self::assertTrue($view->invite->matches($view->token));
        self::assertSame('reddit u/foo', $view->invite->note);
        self::assertSame($admin, $view->invite->createdBy);
        self::assertNotNull($view->invite->id);

        $record = $audit->record('billing.beta_invite_created');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['betaInviteId' => (string) $view->invite->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('beta_invite', $record->subject->type);
        self::assertSame((string) $view->invite->id, $record->subject->id);
    }

    public function test_revoke_ends_an_unused_invite_and_records_it(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $invite = $this->invite($this->user('revoke-admin'));

        self::getContainer()->get(RevokeBetaInviteHandler::class)(new RevokeBetaInviteCommand($invite));

        self::assertTrue($invite->isRevoked());
        $record = $audit->record('billing.beta_invite_revoked');
        self::assertSame(['betaInviteId' => (string) $invite->id], $record->context);
    }

    public function test_revoking_a_redeemed_invite_is_a_domain_error(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $invite = $this->invite($this->user('redeemed-admin'));
        $invite->redeem($this->user('redeemed-user'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        try {
            self::getContainer()->get(RevokeBetaInviteHandler::class)(new RevokeBetaInviteCommand($invite));
            self::fail('Revoking a redeemed invite should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['invite' => 'billing.admin.beta_invite.error.redeemed'], $e->errors);
        }

        self::assertFalse($invite->isRevoked());
        self::assertSame([], $audit->records('billing.beta_invite_revoked'));
    }

    /**
     * The lock that serializes a revoke against a redemption cannot be shown
     * here: the test runs inside one connection's transaction.
     */
    public function test_revoking_twice_is_a_domain_error(): void
    {
        self::bootKernel();
        $invite = $this->invite($this->user('twice-admin'));
        $handler = self::getContainer()->get(RevokeBetaInviteHandler::class);
        $handler(new RevokeBetaInviteCommand($invite));

        try {
            $handler(new RevokeBetaInviteCommand($invite));
            self::fail('Revoking a revoked invite should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['invite' => 'billing.admin.beta_invite.error.revoked'], $e->errors);
        }
    }

    public function test_reissue_revokes_the_old_invite_and_issues_a_new_one_with_the_same_note(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $admin = $this->user('reissue-admin');
        $old = $this->invite($this->user('reissue-creator'));

        $view = self::getContainer()->get(ReissueBetaInviteHandler::class)(new ReissueBetaInviteCommand($old, $admin));

        self::assertTrue($old->isRevoked());
        self::assertTrue($view->invite->isUsable());
        self::assertTrue($view->invite->matches($view->token));
        self::assertFalse($old->matches($view->token));
        self::assertSame('note', $view->invite->note);
        self::assertSame($admin, $view->invite->createdBy);
        $record = $audit->record('billing.beta_invite_reissued');
        self::assertSame(['betaInviteId' => (string) $view->invite->id, 'replacedBetaInviteId' => (string) $old->id], $record->context);
    }

    public function test_reissuing_a_redeemed_or_revoked_invite_is_a_domain_error(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $admin = $this->user('reissue-refused-admin');
        $handler = self::getContainer()->get(ReissueBetaInviteHandler::class);

        $redeemed = $this->invite($admin);
        $redeemed->redeem($this->user('reissue-refused-user'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $revoked = $this->invite($admin);
        $revoked->revoke();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([[$redeemed, 'redeemed'], [$revoked, 'revoked']] as [$invite, $reason]) {
            try {
                $handler(new ReissueBetaInviteCommand($invite, $admin));
                self::fail('Reissuing a '.$reason.' invite should be refused.');
            } catch (DomainErrors $e) {
                self::assertSame(['invite' => 'billing.admin.beta_invite.error.'.$reason], $e->errors);
            }
        }

        self::assertSame([], $audit->records('billing.beta_invite_reissued'));
    }

    public function test_the_handlers_keep_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(CreateBetaInviteHandler::class);
        DirectLogging::assertRemovedFrom(RevokeBetaInviteHandler::class);
        DirectLogging::assertRemovedFrom(ReissueBetaInviteHandler::class);
    }

    private function invite(User $createdBy): BetaInvite
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$invite] = BetaInvite::issue($createdBy, 'note');
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function user(string $prefix): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Test', $prefix.'@beta-handler.example.com', 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
