<?php

declare(strict_types=1);

namespace App\Module\Billing\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * One billing state per demo account, and a few beta invites, so the billing
 * page, the admin account page and the invite list show records. The seed also
 * switches on `billing.enabled`, and the dev and admin accounts hold a comp with
 * no end, so the paywall never shuts them out when a trial would end. The
 * option --keep-flag-defaults skips the seeder, because the e2e suite starts
 * from the coded flag defaults and sets its own billing state.
 */
#[When('dev')]
#[When('test')]
final readonly class DevBillingSeeder implements DevSeederInterface
{
    public const string KEY = 'billing';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private BillingProfileRepository $billingProfiles,
        private BetaInviteRepository $betaInvites,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        if ($context->keepFlagDefaults) {
            return;
        }

        $now = $context->now;
        $dev = $context->devUser();
        $admin = $context->adminUser();
        $this->comp($context, 'dev', $dev, $admin);
        $this->comp($context, 'admin', $admin, $admin);

        $member = $this->users->findOneByEmail(DevAccountSeeder::MEMBER_EMAIL);
        if ($member instanceof User) {
            $profile = $this->profile($context, 'member', $member);
            $profile->stripeCustomerId ??= 'cus_dev_member';
            $renewsAt = $now->modify('+10 days');
            $stored = $this->em->find(Subscription::class, DevSeedId::of('billing/subscription/member-stripe'));
            if ($stored instanceof Subscription) {
                $stored->endsAt = $renewsAt;
            }
            $stripe = $this->subscription($context, 'member-stripe', $profile, SubscriptionKind::Stripe, $now->modify('-20 days'), $renewsAt);
            if ($stripe instanceof Subscription) {
                $stripe->stripeSubscriptionId = 'sub_dev_member';
                $stripe->stripeStatus = BillingStatus::Active;
            }
        }

        $suspended = $this->users->findOneByEmail(DevAccountSeeder::SUSPENDED_EMAIL);
        if ($suspended instanceof User) {
            $profile = $this->profile($context, 'suspended', $suspended);
            $this->subscription($context, 'suspended-trial', $profile, SubscriptionKind::Trial, $now->modify('-30 days'), $now->modify('-16 days'));
        }
        $this->em->flush();

        $this->invite($context, 'open', 'Open invite for a tester.', $now->modify('-3 days'), null, false);
        $this->invite($context, 'redeemed', 'Redeemed by the member account.', $now->modify('-9 days'), $member instanceof User ? $member : null, false);
        $this->invite($context, 'revoked', 'Revoked before use.', $now->modify('-12 days'), null, true);
        $this->em->flush();
    }

    private function comp(SeedContext $context, string $key, User $user, User $grantedBy): void
    {
        $profile = $this->profile($context, $key, $user);
        $comp = $this->subscription($context, $key.'-comp', $profile, SubscriptionKind::Comp, $context->now->modify('-30 days'), null);
        if (null !== $comp) {
            $comp->grantedBy = $grantedBy;
        }
    }

    private function profile(SeedContext $context, string $key, User $user): BillingProfile
    {
        $profile = $this->billingProfiles->findOneByUser($user);
        if ($profile instanceof BillingProfile) {
            return $profile;
        }

        $profile = new BillingProfile($user, $context->now->modify('-30 days'));
        $context->ids->assign($profile, DevSeedId::of('billing/profile/'.$key));
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    /** @return ?Subscription null when the row exists already */
    private function subscription(SeedContext $context, string $key, BillingProfile $profile, SubscriptionKind $kind, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): ?Subscription
    {
        $id = DevSeedId::of('billing/subscription/'.$key);
        if ($this->em->find(Subscription::class, $id) instanceof Subscription) {
            return null;
        }
        // Another grant of this kind may be current already, such as the trial that the first request of an account creates.
        if ($profile->currentSubscriptionOfKind($kind, $startsAt) instanceof Subscription) {
            return null;
        }

        $subscription = new Subscription($profile, $kind, $startsAt, $endsAt, $startsAt);
        $context->ids->assign($subscription, $id);
        $this->em->persist($subscription);

        return $subscription;
    }

    private function invite(SeedContext $context, string $key, string $note, \DateTimeImmutable $createdAt, ?User $redeemedBy, bool $revoked): void
    {
        $id = DevSeedId::of('billing/beta-invite/'.$key);
        if ($this->betaInvites->find($id) instanceof BetaInvite) {
            return;
        }

        [$invite] = BetaInvite::issue(null, $note);
        $invite->token = hash('sha256', 'dev-seed-beta-invite-'.$key);
        $invite->createdAt = $createdAt;
        if ($redeemedBy instanceof User) {
            $invite->redeemedBy = $redeemedBy;
            $invite->redeemedAt = $createdAt->modify('+1 day');
        }
        if ($revoked) {
            $invite->revokedAt = $createdAt->modify('+2 days');
        }
        $context->ids->assign($invite, $id);
        $this->em->persist($invite);
    }
}
