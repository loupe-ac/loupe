<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\Admin\UpdateUserCommand;
use App\Module\Account\Command\Admin\UpdateUserHandler;
use App\Module\Account\Command\LinkSocialAccountCommand;
use App\Module\Account\Command\LinkSocialAccountHandler;
use App\Module\Account\Command\MarkEmailVerifiedCommand;
use App\Module\Account\Command\MarkEmailVerifiedHandler;
use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Command\VerifyEmailCommand;
use App\Module\Account\Command\VerifyEmailHandler;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Event\UserEmailVerified;
use App\Module\Account\Service\SocialProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Each path that can verify an existing account announces it once, and only
 * when the account was unverified before.
 */
final class UserEmailVerifiedDispatchTest extends KernelTestCase
{
    /** @var list<UserEmailVerified> */
    private array $announced = [];
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(UserEmailVerified::class, function (UserEmailVerified $event): void {
            $this->announced[] = $event;
        });
    }

    public function test_verifying_by_link_announces_an_unverified_account(): void
    {
        $user = $this->user('link-unverified@example.com', verified: false);
        $token = $user->generateEmailVerificationToken();
        $this->em->flush();

        self::assertSame($user, $this->handler(VerifyEmailHandler::class)(new VerifyEmailCommand($token)));

        $this->assertAnnounced($user);
    }

    public function test_verifying_by_link_announces_nothing_for_a_verified_account(): void
    {
        $user = $this->user('link-verified@example.com', verified: true);
        $token = $user->generateEmailVerificationToken();
        $this->em->flush();

        self::assertSame($user, $this->handler(VerifyEmailHandler::class)(new VerifyEmailCommand($token)));

        self::assertSame([], $this->announced);
    }

    public function test_a_provider_login_that_auto_links_announces_an_unverified_account(): void
    {
        $user = $this->user('auto-unverified@example.com', verified: false, password: null);
        $this->em->flush();

        $this->handler(ResolveSocialLoginHandler::class)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-auto-unverified', 'auto-unverified@example.com', 'Auto', emailVerified: true),
        ));

        $this->assertAnnounced($user);
    }

    public function test_a_provider_login_that_auto_links_announces_nothing_for_a_verified_account(): void
    {
        $this->user('auto-verified@example.com', verified: true, password: null);
        $this->em->flush();

        $outcome = $this->handler(ResolveSocialLoginHandler::class)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-auto-verified', 'auto-verified@example.com', 'Auto', emailVerified: true),
        ));

        self::assertFalse($outcome->requiresPasswordLink, 'the auto-link branch ran');
        self::assertSame([], $this->announced);
    }

    public function test_a_confirmed_link_announces_an_unverified_account(): void
    {
        $user = $this->user('confirm-unverified@example.com', verified: false);
        $this->em->flush();

        $this->link($user, 'g-confirm-unverified');

        $this->assertAnnounced($user);
    }

    public function test_a_confirmed_link_announces_nothing_for_a_verified_account(): void
    {
        $user = $this->user('confirm-verified@example.com', verified: true);
        $this->em->flush();

        $this->link($user, 'g-confirm-verified');

        self::assertSame([], $this->announced);
    }

    public function test_the_console_command_announces_an_unverified_account(): void
    {
        $user = $this->user('console-unverified@example.com', verified: false);
        $this->em->flush();

        $this->handler(MarkEmailVerifiedHandler::class)(new MarkEmailVerifiedCommand('console-unverified@example.com'));

        $this->assertAnnounced($user);
    }

    public function test_the_console_command_announces_nothing_for_a_verified_account(): void
    {
        $user = $this->user('console-verified@example.com', verified: true);
        $user->generateEmailVerificationToken();
        $this->em->flush();

        // A revoked token proves the handler ran past its early return.
        $result = $this->handler(MarkEmailVerifiedHandler::class)(new MarkEmailVerifiedCommand('console-verified@example.com'));

        self::assertTrue($result->tokenRevoked);
        self::assertSame([], $this->announced);
    }

    public function test_the_admin_form_announces_a_newly_verified_account(): void
    {
        $actor = $this->user('admin-actor@example.com', verified: true);
        $target = $this->user('admin-unverified@example.com', verified: false);
        $this->em->flush();

        $this->update($target, $actor, isVerified: true);

        $this->assertAnnounced($target);
    }

    public function test_the_admin_form_announces_nothing_for_an_unrelated_edit(): void
    {
        $actor = $this->user('admin-actor2@example.com', verified: true);
        $target = $this->user('admin-verified@example.com', verified: true);
        $this->em->flush();

        $this->update($target, $actor, isVerified: true, fullName: 'Renamed');

        self::assertSame('Renamed', $target->fullName);
        self::assertSame([], $this->announced);
    }

    public function test_the_admin_form_announces_nothing_when_a_new_address_resets_verification(): void
    {
        $actor = $this->user('admin-actor3@example.com', verified: true);
        $target = $this->user('admin-changed@example.com', verified: true);
        $this->em->flush();

        $this->update($target, $actor, isVerified: true, email: 'admin-changed-new@example.com');

        self::assertFalse($target->isVerified());
        self::assertSame([], $this->announced);
    }

    private function assertAnnounced(User $user): void
    {
        self::assertCount(1, $this->announced);
        self::assertSame($user->id, $this->announced[0]->user->id);
        self::assertTrue($this->announced[0]->user->isVerified(), 'the event follows the flush');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        return $handler;
    }

    private function link(User $user, string $providerUserId): void
    {
        $this->handler(LinkSocialAccountHandler::class)(new LinkSocialAccountCommand(
            userId: (string) $user->id,
            profile: new SocialProfile(SocialProvider::Google, $providerUserId, $user->email, 'Linker', emailVerified: true),
            plainPassword: 'correct horse',
        ));
    }

    private function update(User $target, User $actor, bool $isVerified, ?string $email = null, ?string $fullName = null): void
    {
        $this->handler(UpdateUserHandler::class)(new UpdateUserCommand(
            target: $target,
            actor: $actor,
            fullName: $fullName ?? $target->fullName,
            email: $email ?? $target->email,
            isAdmin: false,
            isVerified: $isVerified,
        ));
    }

    /**
     * @param non-empty-string $email
     */
    private function user(string $email, bool $verified, ?string $password = 'placeholder'): User
    {
        $user = new User(fullName: 'Test User', email: $email, password: $password);
        if (null !== $password) {
            $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            $user->password = $hasher->hashPassword($user, 'correct horse');
        }
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }
        $this->em->persist($user);

        return $user;
    }
}
