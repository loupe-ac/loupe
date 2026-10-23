<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Event\UserRegistered;
use App\Module\Account\EventListener\NotifyOwnersOnWaitlistInviteClaimed;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\WaitlistNotificationRecipients;
use App\Module\Account\Service\WaitlistOwnerNotifier;
use App\Module\Billing\EventListener\ProvisionTrialOnUserRegistered;
use App\Tests\Support\InstalledInstance;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class WaitlistInviteClaimEmailTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        InstalledInstance::ensure(self::getContainer());
        $this->setRecipients('owner@example.com');
    }

    public function test_a_form_registration_with_an_invite_emails_the_owner(): void
    {
        $token = $this->invite('claimer@example.com', '-2 days');

        $this->register('claimer@example.com', $token);

        $html = $this->ownerEmailHtml();
        self::assertStringContainsString('claimer@example.com created an account with the registration form.', $html);
        self::assertStringContainsString(
            \sprintf('The invite was sent on %s, and the person claimed it 2 days later.', new \DateTimeImmutable('-2 days')->format('Y-m-d')),
            $html,
        );
        self::assertStringContainsString('/admin/users/', $html);
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('claimer@example.com');
        self::assertNotNull($user);
        self::assertStringContainsString('/admin/users/'.$user->id.'"', $html);
    }

    public function test_a_social_registration_with_an_invite_names_the_provider(): void
    {
        $token = $this->invite('social-claimer@example.com', '-1 day');
        $handler = self::getContainer()->get(ResolveSocialLoginHandler::class);
        self::assertInstanceOf(ResolveSocialLoginHandler::class, $handler);

        $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-1', 'social-claimer@example.com', 'Social Claimer', true),
            $token,
        ));

        self::assertStringContainsString(
            'social-claimer@example.com created an account with GitHub.',
            $this->ownerEmailHtml(),
        );
    }

    public function test_a_registration_with_no_invite_sends_nothing_to_the_owner(): void
    {
        $this->em->persist(new WaitlistEntry('walk-in@example.com'));
        $this->em->flush();

        $this->register('walk-in@example.com', null);

        self::assertSame([], $this->ownerEmails());
        $entry = self::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('walk-in@example.com');
        self::assertNotNull($entry?->convertedAt);
        self::assertNull($entry->inviteClaimedAt);
    }

    public function test_an_invite_for_another_address_sends_nothing(): void
    {
        $token = $this->invite('invited@example.com', '-1 day');

        $this->register('someone-else@example.com', $token);

        self::assertNotNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('someone-else@example.com'));
        $entry = self::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('invited@example.com');
        self::assertNotNull($entry);
        self::assertNull($entry->inviteClaimedAt);
        self::assertNull($entry->convertedAt);
        self::assertSame([], $this->ownerEmails());
    }

    public function test_an_invite_for_an_address_with_an_account_is_refused_and_not_claimed(): void
    {
        $this->em->persist(new User(fullName: 'Existing', email: 'taken@example.com', password: 'x'));
        $this->em->flush();
        $token = $this->invite('taken@example.com', '-1 day');

        try {
            $this->register('taken@example.com', $token);
            self::fail('The registration must be refused.');
        } catch (DomainErrors) {
        }

        $this->em->clear();
        $entry = self::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('taken@example.com');
        self::assertNotNull($entry);
        self::assertNull($entry->inviteClaimedAt);
        self::assertNull($entry->convertedAt);
        self::assertSame([], $this->ownerEmails());
    }

    public function test_an_empty_list_sends_nothing_and_still_registers(): void
    {
        $this->setRecipients('');
        $token = $this->invite('quiet@example.com', '-1 day');

        $this->register('quiet@example.com', $token);

        self::assertSame([], $this->ownerEmails());
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('quiet@example.com'));
    }

    public function test_a_lookup_failure_is_logged_and_never_reaches_the_dispatcher(): void
    {
        $repo = $this->createStub(WaitlistEntryRepository::class);
        $repo->method('findOneClaimedByEmail')->willThrowException(new \RuntimeException('db gone'));
        $notifier = self::getContainer()->get(WaitlistOwnerNotifier::class);
        self::assertInstanceOf(WaitlistOwnerNotifier::class, $notifier);
        $logger = new RecordingLogger();

        new NotifyOwnersOnWaitlistInviteClaimed($repo, $notifier, $logger)(
            new UserRegistered(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), null),
        );

        self::assertCount(1, $logger->records);
        self::assertSame('account.waitlist_notification_failed', $logger->records[0]['message']);
        self::assertStringNotContainsString('@', json_encode($logger->records[0]['context'], \JSON_THROW_ON_ERROR));
    }

    public function test_the_listener_runs_before_the_trial_listener(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $classes = array_map(
            static fn (mixed $listener): string => \is_array($listener) && \is_object($listener[0])
                ? $listener[0]::class
                : (\is_object($listener) ? $listener::class : ''),
            $dispatcher->getListeners(UserRegistered::class),
        );

        $claim = array_search(NotifyOwnersOnWaitlistInviteClaimed::class, $classes, true);
        $trial = array_search(ProvisionTrialOnUserRegistered::class, $classes, true);

        self::assertIsInt($claim);
        self::assertIsInt($trial);
        self::assertLessThan($trial, $claim);
    }

    /** @param non-empty-string $email */
    private function invite(string $email, string $invitedAgo): string
    {
        $entry = new WaitlistEntry($email);
        $token = $entry->issueInviteToken();
        $entry->invitedAt = new \DateTimeImmutable($invitedAgo);
        $this->em->persist($entry);
        $this->em->flush();

        return $token;
    }

    /** @param non-empty-string $email */
    private function register(string $email, ?string $token): void
    {
        $handler = self::getContainer()->get(RegisterUserHandler::class);
        self::assertInstanceOf(RegisterUserHandler::class, $handler);
        $handler(new RegisterUserCommand(email: $email, fullName: 'Riley Chen', plainPassword: 'SecurePassword1!', inviteToken: $token));
    }

    /** @return list<TemplatedEmail> */
    private function ownerEmails(): array
    {
        $found = [];
        foreach ($this->getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail && 'owner@example.com' === $message->getTo()[0]->getAddress()) {
                $found[] = $message;
            }
        }

        return $found;
    }

    private function ownerEmailHtml(): string
    {
        $emails = $this->ownerEmails();
        self::assertCount(1, $emails);
        $html = $emails[0]->getHtmlBody();
        self::assertIsString($html);

        return $html;
    }

    private function setRecipients(string $value): void
    {
        $flag = self::getContainer()->get(FeatureFlagRepository::class)->findOneBy(['name' => WaitlistNotificationRecipients::FLAG]);
        self::assertInstanceOf(FeatureFlag::class, $flag);
        $flag->value = $value;
        $this->em->flush();
    }
}
